<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every batch of a queued ingest is written (after a resume too); $failedBatches is 0.
 */
final class VectorIngestCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $store,
        public readonly string $collection,
        public readonly string $runId,
        public readonly int $processed,
        public readonly int $total,
        public readonly int $failedBatches,
    ) {}

    /**
     * Dispatch unless package events are disabled (`vector.events.enabled`).
     */
    public static function record(string $store, string $collection, string $runId, int $processed, int $total, int $failedBatches): void
    {
        if (! (bool) config('vector.events.enabled', true)) {
            return;
        }

        event(new self($store, $collection, $runId, $processed, $total, $failedBatches));
    }
}
