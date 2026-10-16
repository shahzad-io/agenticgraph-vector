<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A migration copied a batch or changed phase (copying, catching_up, switched, rolled_back, dropped).
 */
final class VectorMigrationProgressed
{
    use Dispatchable;

    public function __construct(
        public readonly string $store,
        public readonly string $alias,
        public readonly string $source,
        public readonly string $target,
        public readonly string $phase,
        public readonly int $copied,
        public readonly int $total,
    ) {}

    /**
     * Dispatch unless package events are disabled (`vector.events.enabled`).
     */
    public static function record(string $store, string $alias, string $source, string $target, string $phase, int $copied, int $total): void
    {
        if (! (bool) config('vector.events.enabled', true)) {
            return;
        }

        event(new self($store, $alias, $source, $target, $phase, $copied, $total));
    }
}
