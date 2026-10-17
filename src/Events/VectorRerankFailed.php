<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

/**
 * A reranker request failed or timed out. $failedOpen: the caller got the input order instead
 * (`vector.rerank.fail_open`); otherwise the exception was thrown on.
 */
final class VectorRerankFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $reranker,
        public readonly string $model,
        public readonly int $documentCount,
        public readonly int $durationMs,
        public readonly Throwable $exception,
        public readonly bool $failedOpen,
    ) {}
}
