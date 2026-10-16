<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One embedding request (`embed` or `embedBatch`) finished. $tokens is the provider's reported
 * usage, null when the provider reports none.
 */
final class VectorEmbeddingCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $model,
        public readonly int $inputCount,
        public readonly ?int $tokens,
        public readonly int $durationMs,
    ) {}

    /**
     * Dispatch unless package events are disabled (`vector.events.enabled`).
     */
    public static function record(string $model, int $inputCount, ?int $tokens, int $startedHrtime): void
    {
        if (! (bool) config('vector.events.enabled', true)) {
            return;
        }

        event(new self($model, $inputCount, $tokens, (int) round((hrtime(true) - $startedHrtime) / 1_000_000)));
    }
}
