<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Test-only stub of agenticgraph's TraceContext: the constructor and the accessors TraceSpan uses.
 */
final class TraceContext
{
    private ?string $currentSpanId = null;

    /**
     * @param  list<array{trace_id: string, span_id: string}>  $links
     */
    public function __construct(
        private readonly string $executionId,
        private readonly string $rootSpanId,
        private readonly array $links = [],
    ) {
        $this->currentSpanId = $rootSpanId;
    }

    /**
     * Unlike the real one, the id is `span_{name}`, so tests can name it.
     */
    public function startSpan(string $nodeName): string
    {
        return $this->currentSpanId = "span_{$nodeName}";
    }

    public static function tryGet(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }

    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    public function getSpanId(): ?string
    {
        return $this->currentSpanId;
    }

    public function getRootSpanId(): string
    {
        return $this->rootSpanId;
    }

    /**
     * @return list<array{trace_id: string, span_id: string}>
     */
    public function getLinks(): array
    {
        return $this->links;
    }

    public function isEnabled(): bool
    {
        return (bool) config('agenticgraph.tracing.enabled', false);
    }
}
