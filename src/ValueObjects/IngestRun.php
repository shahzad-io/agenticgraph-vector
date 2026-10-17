<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

/**
 * A queued ingest as recorded in `vector_ingest_runs`.
 *
 * Status: pending (dispatched), running, completed (every batch written) or failed (every batch ran, at least
 * one failed after its last try). Batch status: pending, done or failed.
 */
final readonly class IngestRun
{
    /**
     * @param  array<int, array{status: string, count: int, attempts: int, error: string|null}>  $batches  by batch index
     */
    public function __construct(
        public string $id,
        public ?string $batchId,
        public string $store,
        public string $collection,
        public string $status,
        public int $totalDocuments,
        public int $processedDocuments,
        public array $batches,
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
    ) {}

    /**
     * @return array<int, array{status: string, count: int, attempts: int, error: string|null}>
     */
    public function failedBatches(): array
    {
        return array_filter($this->batches, static fn (array $batch): bool => $batch['status'] === 'failed');
    }
}
