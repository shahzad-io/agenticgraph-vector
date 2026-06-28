<?php

namespace Shahzad\AgenticGraph\Tracing;

final class Tracer
{
    /**
     * @param  callable(): mixed  $callback
     * @param  array<string, mixed>  $attributes
     */
    public function span(string $name, callable $callback, array &$attributes = [], string $spanType = 'custom'): mixed {}
}
