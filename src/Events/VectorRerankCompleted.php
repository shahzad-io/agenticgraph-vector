<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One reranker request finished. $reranker is the config name; $units and $tokens are what the provider
 * reports (null when it reports none). Money is not computed here: the package has no price table.
 */
final class VectorRerankCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $reranker,
        public readonly string $model,
        public readonly int $documentCount,
        public readonly ?int $units,
        public readonly ?int $tokens,
        public readonly int $durationMs,
    ) {}
}
