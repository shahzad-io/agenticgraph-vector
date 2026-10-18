<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Instrumentation;

/**
 * Test-only stub of agenticgraph's HttpClientInstrumentation: records the executions cleaned up.
 */
final class HttpClientInstrumentation
{
    /** @var list<string> */
    public array $cleaned = [];

    public function cleanupExecution(string $executionId): void
    {
        $this->cleaned[] = $executionId;
    }
}
