<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tracing;

use AgenticGraph\Vector\ValueObjects\SearchHit;
use AgenticGraph\Vector\ValueObjects\SearchResult;

/**
 * Frozen vector span attribute contract (v1).
 *
 * @see docs/PRD.md Section 6
 */
final class VectorSpanSchema
{
    public const VERSION = 'v1';

    /** @var list<string> */
    public const FROZEN_ATTRIBUTE_KEYS = [
        'vector.store',
        'vector.collection',
        'vector.operation',
        'vector.top_k',
        'vector.result_count',
        'vector.latency_ms',
        'vector.score_min',
        'vector.score_max',
        'vector.filter_count',
    ];

    /**
     * @return array<string, int|string|float>
     */
    public static function baseAttributes(string $operation, VectorTraceContext $context): array
    {
        $attributes = [
            'vector.store' => $context->store,
            'vector.collection' => $context->collection,
            'vector.operation' => $operation,
        ];

        if ($context->searchQuery !== null) {
            $attributes['vector.top_k'] = $context->searchQuery->topK;
        }

        if ($context->filterCount > 0) {
            $attributes['vector.filter_count'] = $context->filterCount;
        }

        self::assertOnlyFrozenKeys($attributes);

        return $attributes;
    }

    /**
     * @param  array<string, int|string|float>  $attributes
     */
    public static function enrichFromResult(array &$attributes, string $operation, mixed $result, int $latencyMs): void
    {
        $attributes['vector.latency_ms'] = $latencyMs;

        if ($result instanceof SearchResult) {
            $attributes['vector.result_count'] = $result->count();

            if ($operation === 'search' && $result->hits !== []) {
                $scores = array_map(
                    static fn (SearchHit $hit): float => $hit->score,
                    $result->hits,
                );

                $attributes['vector.score_min'] = min($scores);
                $attributes['vector.score_max'] = max($scores);
            }
        }

        self::assertOnlyFrozenKeys($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function assertOnlyFrozenKeys(array $attributes): void
    {
        foreach (array_keys($attributes) as $key) {
            if (! in_array($key, self::FROZEN_ATTRIBUTE_KEYS, true)) {
                throw new \InvalidArgumentException("Vector span attribute [{$key}] is not part of the frozen v1 schema.");
            }
        }
    }
}
