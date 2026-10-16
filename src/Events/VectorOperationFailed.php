<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class VectorOperationFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $operation,
        public readonly string $store,
        public readonly string $collection,
        public readonly int $durationMs,
        public readonly Throwable $exception,
        public readonly ?int $documentCount = null,
        public readonly ?int $embeddingMs = null,
        public readonly ?string $searchMode = null,
    ) {}
}
