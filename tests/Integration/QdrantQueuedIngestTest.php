<?php

use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Ingest\QueuedIngest;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\Tests\Support\QdrantTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Queued ingest on a live Qdrant through a real worker. The vectors live in Qdrant; the run record, job batches
 * and failed jobs live in Postgres, the app's database.
 */

beforeEach(function () {
    if (! QdrantTestHelper::isAvailable()) {
        $this->markTestSkipped(QdrantTestHelper::skipReason());
    }

    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    config()->set('vector.stores.qdrant.url', QdrantTestHelper::url());
    config()->set('vector.stores.qdrant.batch_size', 2);
    config()->set('vector.queue.backoff', [0]);
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

    $this->collection = 'queued_'.getmypid().'_'.uniqid();
});

afterEach(function () {
    $this->queueTables?->down();
    DB::table(QueuedIngest::TABLE)->where('collection', $this->collection ?? '')->delete();

    if (isset($this->collection) && Vector::store('qdrant')->collectionExists($this->collection)) {
        Vector::store('qdrant')->deleteCollection($this->collection);
    }
});

it('declares queued ingest and ingests through a real worker on Qdrant', function () {
    $store = Vector::store('qdrant');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));
    $documents = array_map(fn (int $i): Document => Document::make("q{$i}", "queued qdrant document {$i}", ['i' => $i]), range(1, 5));

    $run = $store->collection($this->collection)->queue('database')->upsert($documents);
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 2048]);
    $run = Vector::ingestRun($run->id);

    expect($store->capabilities()->supports(StoreCapability::QueuedIngest))->toBeTrue()
        ->and($run->status)->toBe('completed')
        ->and(count($run->batches))->toBe(3)
        ->and($run->processedDocuments)->toBe(5)
        ->and($store->countDocuments($this->collection))->toBe(5)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});
