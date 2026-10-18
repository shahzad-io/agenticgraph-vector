<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;

/**
 * Records every exporter call as [method, first argument, the rest], on while agenticgraph.tracing.enabled is.
 */
final class RecordingTraceExporter implements TracerExporter
{
    /** @var list<array{0: string, 1: mixed, 2: array<int, mixed>}> */
    public array $calls = [];

    public function isEnabled(): bool
    {
        return (bool) config('agenticgraph.tracing.enabled', false);
    }

    public function startTrace(string $traceId, string $graphName, array $initialStateKeys = [], ?\DateTimeImmutable $startedAt = null, ?array $initialStateData = null): void
    {
        $this->calls[] = ['startTrace', $traceId, [$graphName]];
    }

    public function addSpan(array $span): void
    {
        $this->calls[] = ['addSpan', $span, []];
    }

    public function completeTrace(string $traceId, array $finalStateKeys = [], float $durationMs = 0, ?array $finalStateData = null, ?\DateTimeInterface $at = null): void
    {
        $this->calls[] = ['completeTrace', $traceId, []];
    }

    public function failTrace(string $traceId, \Throwable $exception, float $durationMs = 0, ?array $finalStateData = null, ?\DateTimeInterface $at = null): void
    {
        $this->calls[] = ['failTrace', $traceId, [$exception->getMessage()]];
    }

    public function resumeTrace(string $traceId, ?string $graphName = null, string $kind = 'resumed', ?\DateTimeInterface $at = null): void
    {
        $this->calls[] = ['resumeTrace', $traceId, [$kind]];
    }

    public function flush(string $traceId): void
    {
        $this->calls[] = ['flush', $traceId, []];
    }
}
