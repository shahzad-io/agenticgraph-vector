<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A queued ingest batch was written or failed for good. $processed counts the documents of written batches.
 */
final class VectorIngestProgressed
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
