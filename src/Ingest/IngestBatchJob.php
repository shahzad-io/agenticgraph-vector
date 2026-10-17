<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Ingest;

use AgenticGraph\Vector\Providers\PrismEmbeddingProvider;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\FailOnException;
use Throwable;

/**
 * One batch of a queued ingest: one chunk of the store's batchSize(), written in one transaction. The store is
 * resolved again by name and the collection by its alias, so a batch follows an alias switch. A rerun of a
 * written batch rewrites the same rows (upsert by id) and sets the same counters (by batch index).
 */
final class IngestBatchJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable;

    public int $tries;

    public int $timeout;

    /**
     * @param  array<string, mixed>  $globalMetadata
     * @param  list<Document>  $documents  content and metadata only (queue() refuses precomputed vectors)
     */
    public function __construct(
        public readonly string $store,
        public readonly string $collection,
        public readonly array $globalMetadata,
        public readonly string $runId,
        public readonly int $index,
        public readonly array $documents,
    ) {
        $this->tries = QueuedIngest::tries();
        $this->timeout = QueuedIngest::timeout();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return QueuedIngest::backoff();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new FailOnException(QueuedIngest::failsAtOnce(...))];
    }

    public function handle(VectorManager $vector): void
    {
        QueuedIngest::start($this->runId);

        $upsert = fn () => $vector->store($this->store)->upsert($this->collection, $this->documents, $this->globalMetadata);

        // On a worker the job's tries and backoff retry a failed batch, so each embedding request gets one try.
        // Inline (the sync connection, no job) a batch runs once, so the provider keeps its own retries.
        $this->job !== null ? PrismEmbeddingProvider::withoutRetries($upsert) : $upsert();

        QueuedIngest::batchDone($this->runId, $this->index, $this->attempts());
    }

    /**
     * After the last try, or at once for a non-retryable exception.
     */
    public function failed(Throwable $exception): void
    {
        QueuedIngest::batchFailed($this->runId, $this->index, $this->attempts(), $exception->getMessage());
    }
}
