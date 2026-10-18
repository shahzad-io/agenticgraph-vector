<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Ingest;

use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Events\VectorIngestCompleted;
use AgenticGraph\Vector\Events\VectorIngestFailed;
use AgenticGraph\Vector\Events\VectorIngestProgressed;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException;
use AgenticGraph\Vector\Exceptions\VectorEmbeddingException;
use AgenticGraph\Vector\Exceptions\VectorInvalidMetadataException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Stores\InstrumentedStore;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\IngestRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Builder::queue()->upsert(): one IngestBatchJob per batch through Bus::batch, recorded in `vector_ingest_runs`.
 * Batch state is set per batch index under a row lock, so a retried batch is counted once; the batch that
 * settles the last open index completes the run or fails it. On the sync connection the
 * jobs run inline, one after another, and every batch runs even when an earlier one fails.
 */
final class QueuedIngest
{
    public const TABLE = 'vector_ingest_runs';

    /** Seconds a batch's job timeout keeps above its embedding request timeout, for the write. */
    public const WRITE_MARGIN = 5;

    /**
     * Errors a retry cannot fix: the job fails at once, as it does for a VectorEmbeddingException that is not
     * retryable (see failsAtOnce()). Connection errors and retryable embedding errors retry with backoff.
     */
    public const NON_RETRYABLE = [
        VectorDimensionMismatchException::class,
        VectorInvalidMetadataException::class,
        VectorCollectionNotFoundException::class,
    ];

    /**
     * @param  list<Document>  $documents  without repeated ids (the Builder keeps the last copy)
     * @param  array<string, mixed>  $globalMetadata
     */
    public static function dispatch(InstrumentedStore $store, string $collection, array $documents, array $globalMetadata, ?string $connection, ?string $queue): IngestRun
    {
        $connection ??= ((string) config('vector.queue.connection')) ?: (string) config('queue.default', 'sync');
        $sync = config("queue.connections.{$connection}.driver") === 'sync';

        if (! $sync) {
            self::assertCanQueue($store, $collection, $connection);
        }

        $chunks = array_chunk($documents, max(1, $store->batchSize()));
        $runId = (string) Str::uuid();

        DB::table(self::TABLE)->insert([
            'id' => $runId,
            'store' => $store->name(),
            'collection' => $collection,
            'status' => 'pending',
            'total_documents' => count($documents),
            'processed_documents' => 0,
            'batches' => json_encode((object) array_map(
                static fn (array $chunk): array => ['status' => 'pending', 'count' => count($chunk), 'attempts' => 0, 'error' => null],
                $chunks,
            ), JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $trace = TraceSpan::carry();
        $jobs = array_map(
            static fn (array $chunk, int $index): IngestBatchJob => new IngestBatchJob($store->name(), $collection, $globalMetadata, $runId, $index, $chunk, $trace),
            $chunks,
            array_keys($chunks),
        );

        if ($jobs === []) {
            self::settle($runId);
        } elseif ($sync) {
            foreach ($jobs as $job) {
                try {
                    app()->call([$job, 'handle']);
                } catch (Throwable $exception) {
                    $job->failed($exception);
                }
            }
        } else {
            try {
                $batch = Bus::batch($jobs)
                    ->allowFailures()
                    ->name("vector-ingest:{$runId}")
                    ->onConnection($connection)
                    ->onQueue(((string) ($queue ?? config('vector.queue.queue'))) ?: null)
                    ->dispatch();
            } catch (Throwable $exception) {
                // Nothing was queued: no run to wait for.
                DB::table(self::TABLE)->where('id', $runId)->delete();

                throw $exception;
            }

            DB::table(self::TABLE)->where('id', $runId)->update(['batch_id' => $batch->id, 'updated_at' => now()]);
        }

        return self::find($runId) ?? throw new \LogicException("Ingest run [{$runId}] was not recorded.");
    }

    public static function find(string $runId): ?IngestRun
    {
        $row = DB::table(self::TABLE)->where('id', $runId)->first();

        if ($row === null) {
            return null;
        }

        /** @var array<int, array{status: string, count: int, attempts: int, error: string|null}> $batches */
        $batches = json_decode((string) $row->batches, true, flags: JSON_THROW_ON_ERROR);
        ksort($batches);

        return new IngestRun(
            id: (string) $row->id,
            batchId: $row->batch_id !== null ? (string) $row->batch_id : null,
            store: (string) $row->store,
            collection: (string) $row->collection,
            status: (string) $row->status,
            totalDocuments: (int) $row->total_documents,
            processedDocuments: (int) $row->processed_documents,
            batches: $batches,
            startedAt: $row->started_at !== null ? (string) $row->started_at : null,
            finishedAt: $row->finished_at !== null ? (string) $row->finished_at : null,
        );
    }

    /**
     * vector:ingest:resume: the failed batches run again, so the run is open until they settle.
     */
    public static function reopen(string $runId): void
    {
        DB::table(self::TABLE)->where('id', $runId)->update(['status' => 'running', 'finished_at' => null, 'updated_at' => now()]);
    }

    public static function start(string $runId): void
    {
        DB::table(self::TABLE)->where('id', $runId)->where('status', 'pending')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
    }

    public static function batchDone(string $runId, int $index, int $attempts): void
    {
        self::markBatch($runId, $index, 'done', $attempts, null);
    }

    public static function batchFailed(string $runId, int $index, int $attempts, string $error): void
    {
        self::markBatch($runId, $index, 'failed', $attempts, $error);
    }

    public static function failsAtOnce(Throwable $exception): bool
    {
        foreach (self::NON_RETRYABLE as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return $exception instanceof VectorEmbeddingException && ! $exception->retryable;
    }

    public static function tries(): int
    {
        return max(1, (int) config('vector.queue.tries', 3));
    }

    public static function timeout(): int
    {
        return (int) config('vector.queue.timeout', 60);
    }

    /**
     * @return list<int>
     */
    public static function backoff(): array
    {
        return array_values(array_map(intval(...), (array) config('vector.queue.backoff', [10, 30, 60])));
    }

    private static function assertCanQueue(InstrumentedStore $store, string $collection, string $connection): void
    {
        if (! $store->capabilities()->supports(StoreCapability::QueuedIngest)) {
            throw new VectorUnsupportedOperationException(
                "Store [{$store->name()}] has no queued ingest on queue connection [{$connection}]: its data lives in one process. Use the sync connection (it runs inline) or a store with the QueuedIngest capability.",
            );
        }

        $timeout = self::timeout();
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        if ($timeout < 1 || ($retryAfter !== null && $timeout >= (int) $retryAfter)) {
            throw new InvalidArgumentException(
                "vector.queue.timeout (VECTOR_QUEUE_TIMEOUT, {$timeout} s) must be at least 1 and below the retry_after of queue connection [{$connection}] (".($retryAfter ?? 'unset').' s), or a slow batch can run twice at once.',
            );
        }

        self::assertEmbeddingFitsTimeout($store, $collection, $timeout);
    }

    /**
     * A batch's embedding request runs without provider retries on a worker (the job retries instead), so it
     * takes at most the provider's timeout. That plus a margin for the write must stay below the job timeout,
     * or the worker kills a slow batch before its request times out.
     */
    private static function assertEmbeddingFitsTimeout(InstrumentedStore $store, string $collection, int $timeout): void
    {
        try {
            $provider = $store->describeCollection($collection)['embedding']['provider'] ?? null;
        } catch (VectorCollectionNotFoundException) {
            $provider = null; // the batch fails at once
        }

        $provider ??= (string) config('vector.embeddings.default', 'prism');
        /** @var array<string, mixed> $entry */
        $entry = (array) config("vector.embeddings.providers.{$provider}", []);

        if (($entry['driver'] ?? $provider) !== 'prism') {
            return;
        }

        $requestTimeout = max(1, (int) ($entry['timeout'] ?? 30));

        if ($requestTimeout + self::WRITE_MARGIN >= $timeout) {
            throw new InvalidArgumentException(
                "Embedding provider [{$provider}] timeout ({$requestTimeout} s) plus ".self::WRITE_MARGIN." s for the write is not below vector.queue.timeout ({$timeout} s): "
                ."the worker would kill a slow batch before its request times out. Lower vector.embeddings.providers.{$provider}.timeout, "
                ."or raise vector.queue.timeout and the queue connection's retry_after.",
            );
        }
    }

    private static function markBatch(string $runId, int $index, string $status, int $attempts, ?string $error): void
    {
        $row = DB::transaction(static function () use ($runId, $index, $status, $attempts, $error): ?object {
            $row = DB::table(self::TABLE)->where('id', $runId)->lockForUpdate()->first();

            if ($row === null) {
                return null;
            }

            /** @var array<int, array{status: string, count: int, attempts: int, error: string|null}> $batches */
            $batches = json_decode((string) $row->batches, true, flags: JSON_THROW_ON_ERROR);
            $batches[$index] = ['status' => $status, 'count' => $batches[$index]['count'] ?? 0, 'attempts' => $attempts, 'error' => $error];
            $row->batches = json_encode((object) $batches, JSON_THROW_ON_ERROR);
            $row->processed_documents = self::processed($batches);

            DB::table(self::TABLE)->where('id', $runId)->update([
                'batches' => $row->batches,
                'processed_documents' => $row->processed_documents,
                'updated_at' => now(),
            ]);

            return $row;
        });

        if ($row === null) {
            return;
        }

        $batches = json_decode((string) $row->batches, true, flags: JSON_THROW_ON_ERROR);
        VectorIngestProgressed::record((string) $row->store, (string) $row->collection, $runId, (int) $row->processed_documents, (int) $row->total_documents, self::failed($batches));

        self::settle($runId);
    }

    /**
     * Complete or fail the run once no batch is pending.
     */
    private static function settle(string $runId): void
    {
        $run = self::find($runId);

        if ($run === null || $run->status === 'completed' || in_array('pending', array_column($run->batches, 'status'), true)) {
            return;
        }

        $failed = count($run->failedBatches());

        if ($failed > 0) {
            $changed = DB::table(self::TABLE)->where('id', $runId)->where('status', '!=', 'failed')
                ->update(['status' => 'failed', 'finished_at' => now(), 'updated_at' => now()]);

            if ($changed > 0) {
                VectorIngestFailed::record($run->store, $run->collection, $runId, $run->processedDocuments, $run->totalDocuments, $failed);
            }

            return;
        }

        $changed = DB::table(self::TABLE)->where('id', $runId)->where('status', '!=', 'completed')
            ->update(['status' => 'completed', 'started_at' => $run->startedAt ?? now(), 'finished_at' => now(), 'updated_at' => now()]);

        if ($changed > 0) {
            VectorIngestCompleted::record($run->store, $run->collection, $runId, $run->processedDocuments, $run->totalDocuments, 0);
        }
    }

    /**
     * @param  array<int, array{status: string, count: int}>  $batches
     */
    private static function processed(array $batches): int
    {
        return array_sum(array_map(static fn (array $batch): int => $batch['status'] === 'done' ? $batch['count'] : 0, $batches));
    }

    /**
     * @param  array<int, array{status: string}>  $batches
     */
    private static function failed(array $batches): int
    {
        return count(array_filter($batches, static fn (array $batch): bool => $batch['status'] === 'failed'));
    }
}
