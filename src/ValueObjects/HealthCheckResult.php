<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

final readonly class HealthCheckResult
{
    public function __construct(
        public bool $healthy,
        public int $latencyMs,
    ) {}
}
