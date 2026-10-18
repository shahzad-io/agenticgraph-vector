<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Contracts;

/**
 * Test-only stub of agenticgraph's TracerExporter: the methods TraceSpan calls, with the same signatures.
 */
interface TracerExporter
{
    public function isEnabled(): bool;

    /**
     * @param  array<int, string>  $initialStateKeys
     * @param  array<string, mixed>|null  $initialStateData
     */
    public function startTrace(string $traceId, string $graphName, array $initialStateKeys = [], ?\DateTimeImmutable $startedAt = null, ?array $initialStateData = null): void;

    /**
     * @param  array<string, mixed>  $span
     */
    public function addSpan(array $span): void;

    /**
     * @param  array<int, string>  $finalStateKeys
     * @param  array<string, mixed>|null  $finalStateData
     */
    public function completeTrace(string $traceId, array $finalStateKeys = [], float $durationMs = 0, ?array $finalStateData = null, ?\DateTimeInterface $at = null): void;

    /**
     * @param  array<string, mixed>|null  $finalStateData
     */
    public function failTrace(string $traceId, \Throwable $exception, float $durationMs = 0, ?array $finalStateData = null, ?\DateTimeInterface $at = null): void;

    public function resumeTrace(string $traceId, ?string $graphName = null, string $kind = 'resumed', ?\DateTimeInterface $at = null): void;

    public function flush(string $traceId): void;
}
