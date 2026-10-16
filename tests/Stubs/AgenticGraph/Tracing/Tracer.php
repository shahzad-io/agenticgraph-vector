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

    /** @var list<array{0: string, 1: array<string, int|string|float>}> span names with their exported attributes */
    public array $spans = [];

    /**
     * @param  callable(): mixed  $callback
     * @param  array<string, int|string|float>  $attributes
     */
    public function span(string $name, callable $callback, array &$attributes = [], string $spanType = 'custom'): mixed
    {
        $result = $callback();
        $this->exportedAttributes = $attributes;
        $this->spans[] = [$name, $attributes];

        return $result;
    }
}
