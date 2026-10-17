<?php

use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Ingest\IngestBatchJob;
use AgenticGraph\Vector\Ingest\QueuedIngest;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Queued ingest on pgvector. The run record, job batches and failed jobs live on the same Postgres connection as
 * the vectors (the default connection in these tests), as in an app with one database.
 */

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    config()->set('vector.stores.pgvector.batch_size', 2);
    config()->set('vector.queue.backoff', [0]);
    // queue:work reads its restart signal from the cache: keep it in memory, whatever the environment sets.
    config()->set('cache.default', 'array');
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'pgvector', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
    config()->set('queue.batching', ['database' => 'pgvector', 'table' => 'job_batches']);
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'pgvector', 'table' => 'failed_jobs']);

    $this->queueTables = require dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations/0001_01_01_000002_testbench_create_jobs_table.php';
    $this->queueTables->down();
    $this->queueTables->up();

    if (! Schema::hasTable(QueuedIngest::TABLE)) {
        (require dirname(__DIR__, 2).'/database/migrations/create_vector_ingest_runs_table.php.stub')->up();
    }

    $this->collection = 'queued_'.uniqid();
});

afterEach(function () {
    $this->queueTables?->down();
    DB::table(QueuedIngest::TABLE)->where('collection', $this->collection ?? '')->delete();
});

function queuedPgDocuments(int $count): array
{
    return array_map(fn (int $i): Document => Document::make("p{$i}", "queued pgvector document {$i}", ['i' => $i]), range(1, $count));
}

it('rebuilds an IVFFlat index once per queued run, not once per batch', function () {
    Vector::store('pgvector')->createCollection(new CollectionDefinition($this->collection, dimensions: 8, index: 'ivfflat'));
    $rebuilds = 0;
    DB::connection('pgvector')->listen(function (QueryExecuted $query) use (&$rebuilds): void {
        $rebuilds += str_starts_with($query->sql, 'DROP INDEX IF EXISTS') ? 1 : 0;
    });

    $run = Vector::store('pgvector')->collection($this->collection)->queue('sync')->upsert(queuedPgDocuments(5));

    expect($run->status)->toBe('completed')
        ->and(count($run->batches))->toBe(3)
        ->and($rebuilds)->toBe(1)
        ->and(Vector::store('pgvector')->describeCollection($this->collection)['index']['type'] ?? null)->toBe('ivfflat');
});

it('ingests through a real worker without duplicates when the run record shares the vector connection', function () {
    Vector::store('pgvector')->createCollection(new CollectionDefinition($this->collection, dimensions: 8));
    $documents = queuedPgDocuments(5);

    $run = Vector::store('pgvector')->collection($this->collection)->queue('database')->upsert($documents);
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0]);

    // A batch delivered twice (a worker killed after its commit) rewrites the same rows.
    $storage = 'vcol_'.substr(hash('sha256', $this->collection), 0, 32);
    $first = DB::table('jobs')->count();
    app()->call([new IngestBatchJob('pgvector', $this->collection, [], $run->id, 0, array_slice($documents, 0, 2)), 'handle']);

    $run = Vector::ingestRun($run->id);
    expect($first)->toBe(0)
        ->and($run->status)->toBe('completed')
        ->and($run->processedDocuments)->toBe(5)
        ->and(DB::connection('pgvector')->scalar("SELECT COUNT(*) FROM {$storage}"))->toBe(5)
        ->and(DB::connection('pgvector')->scalar("SELECT COUNT(DISTINCT id) FROM {$storage}"))->toBe(5)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});
