<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tracing;

use AgenticGraph\Vector\ValueObjects\IdPage;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\UpdateMetadataResult;

/**
 * Frozen vector span attribute contract (v2).
 *
 * v2 (pre-release amendment of v1): adds `vector.embedding_ms`, and `vector.store` is the
 * name of the store actually used, not `vector.default`.
 *
 * @see docs/PRD.md Section 6
 */
final class VectorSpanSchema
{
    public const VERSION = 'v2';

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
        'vector.embedding_ms',
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

        if ($context->topK !== null) {
            $attributes['vector.top_k'] = $context->topK;
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
    public static function enrichFromResult(array &$attributes, string $operation, mixed $result, int $latencyMs, ?int $embeddingMs = null): void
    {
        $attributes['vector.latency_ms'] = $latencyMs;

        if ($embeddingMs !== null) {
            $attributes['vector.embedding_ms'] = $embeddingMs;
        }

        $resultCount = self::resultCount($result);

        if ($resultCount !== null) {
            $attributes['vector.result_count'] = $resultCount;
        }

        if ($operation === 'search' && $result instanceof SearchResult && $result->hits !== []) {
            $scores = array_map(
                static fn (SearchHit $hit): float => $hit->score,
                $result->hits,
            );

            $attributes['vector.score_min'] = min($scores);
            $attributes['vector.score_max'] = max($scores);
        }

        self::assertOnlyFrozenKeys($attributes);
    }

    /**
     * Hits for search and fetch, ids for listIds, updated ids for updateMetadata.
     */
    public static function resultCount(mixed $result): ?int
    {
        return match (true) {
            $result instanceof SearchResult => $result->count(),
            $result instanceof IdPage => count($result->ids),
            $result instanceof UpdateMetadataResult => count($result->updated),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function assertOnlyFrozenKeys(array $attributes): void
    {
        foreach (array_keys($attributes) as $key) {
            if (! in_array($key, self::FROZEN_ATTRIBUTE_KEYS, true)) {
                throw new \InvalidArgumentException("Vector span attribute [{$key}] is not part of the frozen v2 schema.");
            }
        }
    }
}
