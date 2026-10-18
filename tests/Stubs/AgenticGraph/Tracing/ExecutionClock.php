<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Test-only stub of agenticgraph's ExecutionClock: the constructor and the id TraceSpan binds it with.
 */
final class ExecutionClock
{
    public function __construct(
        private readonly string $executionId,
        private readonly ?float $traceStartMicrotime = null,
    ) {}

    public function getExecutionId(): string
    {
        return $this->executionId;
    }
}
