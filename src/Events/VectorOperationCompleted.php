<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class VectorOperationCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $operation,
        public readonly string $store,
        public readonly string $collection,
        public readonly int $durationMs,
        public readonly ?int $resultCount = null,
        public readonly ?int $documentCount = null,
    ) {}
}
