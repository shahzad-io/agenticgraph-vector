<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Test-only Tracer stub: mirrors by-ref span() and captures post-callback attributes.
 */
final class Tracer
{
    /** @var array<string, int|string|float>|null */
    public ?array $exportedAttributes = null;

    /** @var list<array{0: string, 1: array<string, int|string|float>, 2: string|null, 3: string|null, 4: list<array{trace_id: string, span_id: string}>}> span names with their exported attributes, trace id, parent span id and the context links */
    public array $spans = [];

    /**
     * @param  callable(): mixed  $callback
     * @param  array<string, int|string|float>  $attributes
     */
    public function span(string $name, callable $callback, array &$attributes = [], string $spanType = 'custom'): mixed
    {
        // Like the real Tracer, a failed callback still records its span.
        try {
            return $callback();
        } finally {
            $this->exportedAttributes = $attributes;
            $context = TraceContext::tryGet();
            $this->spans[] = [$name, $attributes, $context?->getExecutionId(), $context?->getSpanId(), $context?->getLinks() ?? []];
        }
    }
}
