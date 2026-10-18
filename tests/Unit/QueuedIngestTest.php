<?php

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Events\VectorIngestCompleted;
use AgenticGraph\Vector\Events\VectorIngestFailed;
use AgenticGraph\Vector\Events\VectorIngestProgressed;
use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorConnectionException;
use AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException;
use AgenticGraph\Vector\Exceptions\VectorEmbeddingException;
use AgenticGraph\Vector\Exceptions\VectorInvalidMetadataException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Ingest\IngestBatchJob;
use AgenticGraph\Vector\Ingest\QueuedIngest;
use AgenticGraph\Vector\Providers\DeterministicEmbeddingProvider;
use AgenticGraph\Vector\Stores\MemoryStore;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\Testing\CountingEmbeddingProvider;
use AgenticGraph\Vector\Tests\Support\RecordingTraceExporter;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;
use Illuminate\Bus\PendingBatch;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\Tracer;

/*
 * Queued ingest through a real database queue and an in-process worker (queue:work --stop-when-empty), on an
 * in-memory SQLite connection for the run record, job batches and failed jobs. The store is a memory store that
 * declares QueuedIngest: the worker runs in this process, so it sees the same data (a real memory store refuses).
 */

beforeEach(function () {
    config()->set('database.connections.ingest', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('database.default', 'ingest');
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'ingest', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
    config()->set('queue.batching', ['database' => 'ingest', 'table' => 'job_batches']);
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'ingest', 'table' => 'failed_jobs']);
    config()->set('vector.queue.backoff', [0]);
    // queue:work reads its restart signal from the cache: keep it in memory, whatever the environment sets.
    config()->set('cache.default', 'array');

    (require dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations/0001_01_01_000002_testbench_create_jobs_table.php')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_vector_ingest_runs_table.php.stub')->up();

    $this->embeddings = new CountingEmbeddingProvider(new DeterministicEmbeddingProvider(dimensions: 8));
    $provider = $this->embeddings;
    app(EmbeddingsManager::class)->extend('counting', fn (): CountingEmbeddingProvider => $provider);
    config()->set('vector.embeddings.default', 'counting');

    Vector::extend('queued-memory', fn ($app, array $config): MemoryStore => new MemoryStore(
        $config,
        $app->make(EmbeddingsManager::class),
        $app->make(MetadataValidator::class),
        new StoreCapabilities(StoreCapability::ListIds, StoreCapability::Aliases, StoreCapability::QueuedIngest),
    ));
    config()->set('vector.stores.queued', ['driver' => 'queued-memory', 'batch_size' => 2]);
    Vector::store('queued')->createCollection(new CollectionDefinition('docs', dimensions: 8));
});

function ingestDocuments(int $count, string $prefix = 'd'): array
{
    return array_map(fn (int $i): Document => Document::make("{$prefix}{$i}", "queued document {$i}", ['i' => $i]), range(1, $count));
}

/** A Prism provider (real OpenAI client, Http::fake) behind collection prism_docs, with the default retries. */
function queuedPrismProvider(): void
{
    config()->set('prism.providers.openai.api_key', 'test-key');
    config()->set('prism.providers.openai.url', 'https://api.openai.com/v1');
    config()->set('vector.embeddings.providers.queued_prism', ['driver' => 'prism', 'provider' => 'openai', 'model' => 'm', 'dimensions' => 8]);
    Vector::store('queued')->createCollection(new CollectionDefinition('prism_docs', dimensions: 8, embeddings: 'queued_prism'));
}

function work(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0]);
}

it('dispatches one job per batch through one job batch, after duplicate removal', function () {
    Bus::fake();
    config()->set('queue.connections.redis', ['driver' => 'redis', 'retry_after' => 90]);
    $documents = [...ingestDocuments(4), Document::make('d1', 'last copy of d1')];

    $run = Vector::store('queued')->collection('docs')->queue('redis', 'vectors')->upsert($documents, ['source' => 'kb']);

    Bus::assertBatched(function (PendingBatch $batch) use ($run): bool {
        $jobs = $batch->jobs->all();

        return $batch->allowsFailures()
            && $batch->name === "vector-ingest:{$run->id}"
            && $batch->connection() === 'redis'
            && $batch->queue() === 'vectors'
            && array_map(fn (IngestBatchJob $job): array => [$job->index, array_map(fn (Document $d): string => (string) $d->id, $job->documents)], $jobs)
                === [[0, ['d2', 'd3']], [1, ['d4', 'd1']]]
            && $jobs[1]->documents[1]->content === 'last copy of d1'
            && array_unique(array_map(fn (IngestBatchJob $job): string => "{$job->store}|{$job->collection}|{$job->runId}|".json_encode($job->globalMetadata), $jobs))
                === ["queued|docs|{$run->id}|{\"source\":\"kb\"}"];
    });
    expect($run->status)->toBe('pending')
        ->and($run->batchId)->not->toBeNull()
        ->and($run->totalDocuments)->toBe(4)
        ->and(array_column($run->batches, 'count'))->toBe([2, 2]);
});

it('takes the connection and queue from vector.queue when queue() names none', function () {
    Bus::fake();
    config()->set('queue.connections.redis', ['driver' => 'redis', 'retry_after' => 90]);
    config()->set('vector.queue.connection', 'redis');
    config()->set('vector.queue.queue', 'vectors');

    Vector::store('queued')->collection('docs')->queue()->upsert(ingestDocuments(1));

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->connection() === 'redis' && $batch->queue() === 'vectors');
});

it('sets tries, backoff and timeout from config and fails non-retryable errors at once', function () {
    config()->set('vector.queue.tries', 5);
    config()->set('vector.queue.backoff', [1, 2]);
    config()->set('vector.queue.timeout', 30);

    $job = new IngestBatchJob('queued', 'docs', [], 'run', 0, []);
    $middleware = $job->middleware()[0];
    $fails = function (Throwable $exception) use ($middleware): bool {
        $job = new class
        {
            public bool $failed = false;

            public function fail(): void
            {
                $this->failed = true;
            }
        };

        try {
            $middleware->handle($job, fn () => throw $exception);
        } catch (Throwable) {
        }

        return $job->failed;
    };

    expect([$job->tries, $job->backoff(), $job->timeout])->toBe([5, [1, 2], 30])
        ->and($middleware)->toBeInstanceOf(FailOnException::class)
        ->and(QueuedIngest::NON_RETRYABLE)->toBe([VectorDimensionMismatchException::class, VectorInvalidMetadataException::class, VectorCollectionNotFoundException::class])
        ->and($fails(new VectorInvalidMetadataException('bad')))->toBeTrue()
        ->and($fails(new VectorCollectionNotFoundException('dropped')))->toBeTrue()
        ->and($fails(new VectorEmbeddingException('429', retryable: true, status: 429)))->toBeFalse()
        ->and($fails(new VectorEmbeddingException('400', status: 400)))->toBeTrue()
        ->and($fails(new VectorConnectionException('down')))->toBeFalse();
});

it('ingests through a real worker, one upsert span per batch, and completes the run', function () {
    Event::fake([VectorIngestProgressed::class, VectorIngestCompleted::class, VectorIngestFailed::class, VectorOperationCompleted::class]);

    $run = Vector::store('queued')->collection('docs')->queue('database')->upsert(ingestDocuments(5));
    expect(QueuedIngest::find($run->id)->status)->toBe('pending')
        ->and(Vector::store('queued')->collection('docs')->listIds()->ids)->toBe([]);

    work();

    $run = Vector::ingestRun($run->id);
    expect($run->status)->toBe('completed')
        ->and($run->processedDocuments)->toBe(5)
        ->and(array_column($run->batches, 'status'))->toBe(['done', 'done', 'done'])
        ->and(Vector::store('queued')->collection('docs')->listIds()->ids)->toHaveCount(5)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(Bus::findBatch($run->batchId)->pendingJobs)->toBe(0);
    Event::assertDispatchedTimes(VectorIngestProgressed::class, 3);
    Event::assertDispatched(VectorIngestCompleted::class, fn (VectorIngestCompleted $e): bool => $e->runId === $run->id && $e->processed === 5 && $e->store === 'queued');
    expect(Event::dispatched(VectorOperationCompleted::class, fn (VectorOperationCompleted $e): bool => $e->operation === 'upsert')
        ->map(fn (array $args): ?int => $args[0]->documentCount)->values()->all())->toBe([2, 2, 1]);
});

it('retries an embedding error with backoff and counts the batch once', function () {
    $this->embeddings->failOnBatch = 1;
    $provider = $this->embeddings;
    Queue::exceptionOccurred(function () use ($provider): void {
        $provider->failOnBatch = null;
    });

    $run = Vector::store('queued')->collection('docs')->queue('database')->upsert(ingestDocuments(3));
    work();

    $run = Vector::ingestRun($run->id);
    expect($run->status)->toBe('completed')
        ->and($run->processedDocuments)->toBe(3)
        ->and(array_column($run->batches, 'attempts'))->toBe([2, 1])
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('fails a non-retryable batch on its first try, writes the others and fails the run', function () {
    Event::fake([VectorIngestFailed::class, VectorIngestCompleted::class]);
    $documents = ingestDocuments(6);
    $documents[2] = Document::make('d3', 'bad metadata', ['bad' => new stdClass]);

    $run = Vector::store('queued')->collection('docs')->queue('database')->upsert($documents);
    work();

    $run = Vector::ingestRun($run->id);
    expect($run->status)->toBe('failed')
        ->and($run->processedDocuments)->toBe(4)
        ->and(array_column($run->batches, 'status'))->toBe(['done', 'failed', 'done'])
        ->and($run->batches[1]['attempts'])->toBe(1)
        ->and($run->batches[1]['error'])->toContain('Metadata at [bad]')
        ->and($run->finishedAt)->not->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(Vector::store('queued')->collection('docs')->listIds()->ids)->toBe(['d1', 'd2', 'd5', 'd6']);
    Event::assertDispatched(VectorIngestFailed::class, fn (VectorIngestFailed $e): bool => $e->failedBatches === 1 && $e->processed === 4 && $e->total === 6);
    Event::assertNotDispatched(VectorIngestCompleted::class);
});

it('turns provider retries off inside a worker job: the job retries the batch', function () {
    Sleep::fake();
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'unavailable']], 503)
        ->push(['data' => [['embedding' => array_fill(0, 8, 0.1)], ['embedding' => array_fill(0, 8, 0.2)]], 'usage' => ['total_tokens' => 4], 'model' => 'm'])]);
    queuedPrismProvider();

    $run = Vector::store('queued')->collection('prism_docs')->queue('database')->upsert(ingestDocuments(2));
    work();
    $run = Vector::ingestRun($run->id);

    expect($run->status)->toBe('completed')
        ->and($run->batches[0]['attempts'])->toBe(2);
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
});

it('guard: restores provider retries after a worker job throws inside withoutRetries()', function () {
    Sleep::fake();
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'bad request']], 400)
        ->push(['error' => ['message' => 'unavailable']], 503)
        ->push(['data' => [['embedding' => array_fill(0, 8, 0.1)]], 'usage' => ['total_tokens' => 2], 'model' => 'm'])]);
    queuedPrismProvider();

    $run = Vector::store('queued')->collection('prism_docs')->queue('database')->upsert(ingestDocuments(1));
    work();

    // The job failed (the 400 threw inside withoutRetries()); a plain upsert in the same process still retries.
    Vector::store('queued')->collection('prism_docs')->upsert([Document::make('after', 'plain upsert after the failed job')]);

    expect(Vector::ingestRun($run->id)->status)->toBe('failed')
        ->and(Vector::store('queued')->collection('prism_docs')->listIds()->ids)->toBe(['after']);
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(1);
});

it('guard: keeps provider retries for a queued ingest on the sync connection', function () {
    Sleep::fake();
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'unavailable']], 503)
        ->push(['data' => [['embedding' => array_fill(0, 8, 0.1)], ['embedding' => array_fill(0, 8, 0.2)]], 'usage' => ['total_tokens' => 4], 'model' => 'm'])]);
    queuedPrismProvider();

    $run = Vector::store('queued')->collection('prism_docs')->queue('sync')->upsert(ingestDocuments(2));

    expect($run->status)->toBe('completed')
        ->and($run->batches[0]['attempts'])->toBe(1);
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});

/** Tracing on with the stub Tracer and a recording exporter; the caller's node span span_node is open under span_root in trace exec_caller. */
function queuedInCallerTrace(): RecordingTraceExporter
{
    config()->set('agenticgraph.tracing.enabled', true);
    app()->singleton(Tracer::class);
    app()->instance(TracerExporter::class, $exporter = new RecordingTraceExporter);
    app()->instance(TraceContext::class, $context = new TraceContext('exec_caller', 'span_root'));
    $context->startSpan('node');

    return $exporter;
}

it('carries the caller trace into each batch job: its upsert span hangs off the trace root, links to the caller span, and is sent as a continued segment', function () {
    $exporter = queuedInCallerTrace();
    Vector::store('queued')->collection('docs')->queue('database')->upsert(ingestDocuments(5));
    // The caller's process moves on; the worker has no trace of its own.
    app()->forgetInstance(TraceContext::class);

    work();

    $upserts = array_values(array_filter(app(Tracer::class)->spans, fn (array $span): bool => $span[0] === 'vector.upsert'));
    expect(array_map(fn (array $span): array => [$span[2], $span[3], $span[4]], $upserts))->toBe(array_fill(0, 3, ['exec_caller', 'span_root', [['trace_id' => 'exec_caller', 'span_id' => 'span_node']]]))
        ->and($exporter->calls)->toBe(array_merge(...array_fill(0, 3, [['resumeTrace', 'exec_caller', ['continued']], ['flush', 'exec_caller', []]])))
        ->and(app()->bound(TraceContext::class))->toBeFalse();
});

it('keeps the original trace for a resumed batch', function () {
    config()->set('vector.queue.tries', 1);
    $this->embeddings->failOnBatch = 2;
    queuedInCallerTrace();
    $run = Vector::store('queued')->collection('docs')->queue('database')->upsert(ingestDocuments(4));
    app()->forgetInstance(TraceContext::class);
    work();
    $this->embeddings->failOnBatch = null;
    app()->instance(Tracer::class, new Tracer);

    Artisan::call('vector:ingest:resume', ['run' => $run->id]);
    work();

    expect(Vector::ingestRun($run->id)->status)->toBe('completed')
        ->and(array_map(fn (array $span): array => [$span[0], $span[2], $span[3], $span[4]], app(Tracer::class)->spans))->toBe([['vector.upsert', 'exec_caller', 'span_root', [['trace_id' => 'exec_caller', 'span_id' => 'span_node']]]]);
});

it('guard: an inline batch on the sync connection stays in the running trace and sends no segment of its own', function () {
    $exporter = queuedInCallerTrace();

    Vector::store('queued')->collection('docs')->queue('sync')->upsert(ingestDocuments(3));

    expect($exporter->calls)->toBe([])
        ->and(array_column(app(Tracer::class)->spans, 2))->each->toBe('exec_caller')
        ->and(app(TraceContext::class)->getSpanId())->toBe('span_node');
});

it('fails an embedding error that is not retryable on its first try', function () {
    $inner = new DeterministicEmbeddingProvider(dimensions: 8);
    app(EmbeddingsManager::class)->forgetDrivers()->extend('counting', fn (): EmbeddingProviderContract => new class($inner) implements EmbeddingProviderContract
    {
        public function __construct(private readonly EmbeddingProviderContract $inner) {}

        public function embed(string $text): array
        {
            return $this->inner->embed($text);
        }

        public function embedBatch(array $texts): array
        {
            throw new VectorEmbeddingException('Embedding generation failed: 400', status: 400);
        }

        public function dimensions(): int
        {
            return 8;
        }

        public function model(): string
        {
            return 'deterministic-test';
        }
    });

    $run = Vector::store('queued')->collection('docs')->queue('database')->upsert(ingestDocuments(2));
    work();
    $run = Vector::ingestRun($run->id);

    expect($run->status)->toBe('failed')
        ->and($run->batches[0]['attempts'])->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('resumes a failed run: only the failed batches run again, then the run completes', function () {
    config()->set('vector.queue.tries', 1);
    $this->embeddings->failOnBatch = 2;

    $run = Vector::store('queued')->collection('docs')->queue('database')->upsert(ingestDocuments(6));
    work();
    expect(Vector::ingestRun($run->id)->status)->toBe('failed')
        ->and(array_column(Vector::ingestRun($run->id)->batches, 'status'))->toBe(['done', 'failed', 'failed']);

    $this->embeddings->failOnBatch = null;
    $this->embeddings->reset();
    Event::fake([VectorIngestCompleted::class]);

    expect(Artisan::call('vector:ingest:resume', ['run' => $run->id]))->toBe(0)
        ->and(Vector::ingestRun($run->id)->status)->toBe('running');
    work();

    $run = Vector::ingestRun($run->id);
    expect($run->status)->toBe('completed')
        ->and($run->processedDocuments)->toBe(6)
        ->and($this->embeddings->embedBatchCallCount)->toBe(2)
        ->and(array_column($run->batches, 'status'))->toBe(['done', 'done', 'done'])
        ->and(Vector::store('queued')->collection('docs')->listIds()->ids)->toHaveCount(6)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    Event::assertDispatchedTimes(VectorIngestCompleted::class, 1);
});

it('refuses to resume a run whose failed jobs were pruned, and leaves it failed', function () {
    config()->set('vector.queue.tries', 1);
    $this->embeddings->failOnBatch = 1;

    $run = Vector::store('queued')->collection('docs')->queue('database')->upsert(ingestDocuments(2));
    work();
    DB::table('failed_jobs')->delete();

    expect(Artisan::call('vector:ingest:resume', ['run' => $run->id]))->toBe(1)
        ->and(Artisan::output())->toContain('no longer in the failed job store')
        ->and(Vector::ingestRun($run->id)->status)->toBe('failed');
});

it('refuses to resume an unknown, completed or inline run', function () {
    $inline = Vector::store('queued')->collection('docs')->queue('sync')->upsert(ingestDocuments(1));

    expect(Artisan::call('vector:ingest:resume', ['run' => 'missing']))->toBe(1)
        ->and(Artisan::output())->toContain('No ingest run [missing]')
        ->and(Artisan::call('vector:ingest:resume', ['run' => $inline->id]))->toBe(0)
        ->and(Artisan::output())->toContain('already completed');

    DB::table(QueuedIngest::TABLE)->where('id', $inline->id)->update(['status' => 'failed']);

    expect(Artisan::call('vector:ingest:resume', ['run' => $inline->id]))->toBe(1)
        ->and(Artisan::output())->toContain('ran inline on the sync connection');
});

it('completes an empty queued ingest at once', function () {
    Event::fake([VectorIngestCompleted::class]);

    $run = Vector::store('queued')->collection('docs')->queue('database')->upsert([]);

    expect($run->status)->toBe('completed')->and($run->batches)->toBe([])->and(DB::table('jobs')->count())->toBe(0);
    Event::assertDispatched(VectorIngestCompleted::class, fn (VectorIngestCompleted $e): bool => $e->total === 0);
});

it('refuses a builder over a raw store', function () {
    $raw = new MemoryStore([], app(EmbeddingsManager::class), app(MetadataValidator::class));
    $raw->createCollection(new CollectionDefinition('docs', dimensions: 8));

    expect(fn () => $raw->collection('docs')->queue('sync')->upsert(ingestDocuments(1)))
        ->toThrow(VectorUnsupportedOperationException::class, 'named store from Vector::store()');
});

it('refuses the memory store on a connection other than sync, and runs it inline on sync', function () {
    Vector::store('memory')->createCollection(new CollectionDefinition('docs', dimensions: 8));

    expect(fn () => Vector::store('memory')->collection('docs')->queue('database')->upsert(ingestDocuments(1)))
        ->toThrow(VectorUnsupportedOperationException::class, 'Store [memory] has no queued ingest on queue connection [database]')
        ->and(DB::table(QueuedIngest::TABLE)->count())->toBe(0)
        ->and(Vector::store('memory')->collection('docs')->queue('sync')->upsert(ingestDocuments(3))->status)->toBe('completed')
        ->and(Vector::store('memory')->collection('docs')->listIds()->ids)->toHaveCount(3);
});

it('refuses a job timeout that is not below the connection retry_after', function (int $timeout, ?int $retryAfter, bool $refused) {
    config()->set('vector.queue.timeout', $timeout);
    config()->set('queue.connections.database.retry_after', $retryAfter);
    $queue = fn () => Vector::store('queued')->collection('docs')->queue('database')->upsert(ingestDocuments(1));

    if ($refused) {
        expect($queue)->toThrow(InvalidArgumentException::class, 'VECTOR_QUEUE_TIMEOUT')
            ->and(DB::table(QueuedIngest::TABLE)->count())->toBe(0);
    } else {
        expect($queue()->status)->toBe('pending');
    }
})->with([
    'equal' => [90, 90, true],
    'above' => [120, 90, true],
    'zero (no timeout)' => [0, 90, true],
    'below' => [89, 90, false],
    'no retry_after' => [600, null, false],
]);

it('refuses when the embedding request timeout plus 5 s is not below the job timeout', function (int $timeout, int $retries, bool $refused) {
    config()->set('vector.embeddings.providers.slow', ['driver' => 'prism', 'provider' => 'openai', 'model' => 'm', 'timeout' => $timeout, 'retries' => $retries]);
    Vector::store('queued')->createCollection(new CollectionDefinition('slow_docs', dimensions: 8, embeddings: 'slow'));
    $queue = fn () => Vector::store('queued')->collection('slow_docs')->queue('database')->upsert(ingestDocuments(1));

    if ($refused) {
        expect($queue)->toThrow(InvalidArgumentException::class, 'Embedding provider [slow]')
            ->and(DB::table(QueuedIngest::TABLE)->count())->toBe(0);
    } else {
        expect($queue()->status)->toBe('pending');
    }
})->with([
    // vector.queue.timeout 60. Provider retries are off inside the job, so they do not count.
    'the defaults (30 s, 2 retries)' => [30, 2, false],
    '54 s' => [54, 2, false],
    '55 s (55 + 5 = 60)' => [55, 0, true],
    '55 s with retries' => [55, 5, true],
    '90 s' => [90, 0, true],
]);

it('guard: does not apply the embedding timeout check to a provider without HTTP', function () {
    config()->set('vector.queue.timeout', 2);

    expect(Vector::store('queued')->collection('docs')->queue('database')->upsert(ingestDocuments(1))->status)->toBe('pending');
});

it('removes the run record when the dispatch itself fails', function () {
    config()->set('queue.connections.broken', ['driver' => 'database', 'connection' => 'missing-connection', 'table' => 'jobs', 'retry_after' => 90]);

    expect(fn () => Vector::store('queued')->collection('docs')->queue('broken')->upsert(ingestDocuments(1)))->toThrow(InvalidArgumentException::class)
        ->and(DB::table(QueuedIngest::TABLE)->count())->toBe(0);
});
