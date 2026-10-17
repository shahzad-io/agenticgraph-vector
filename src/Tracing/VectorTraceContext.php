<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tracing;

use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;

final readonly class VectorTraceContext
{
    public function __construct(
        public string $store,
        public string $collection,
        public int $documentCount = 0,
        public ?int $topK = null,
        public int $filterCount = 0,
        public ?string $searchMode = null,
        public ?string $reranker = null,
    ) {}

    public static function forSearch(SearchQuery $query, ?string $store = null): self
    {
        return new self(
            store: $store ?? self::defaultStore(),
            collection: $query->collection,
            topK: $query->topK,
            filterCount: self::filterCount($query->filter),
            searchMode: $query->keywords !== null ? 'hybrid' : 'dense',
        );
    }

    public static function forCollection(string $collection, ?string $store = null, ?NormalizedFilter $filter = null): self
    {
        return new self(
            store: $store ?? self::defaultStore(),
            collection: $collection,
            filterCount: self::filterCount($filter),
        );
    }

    public static function forWrite(
        string $collection,
        int $documentCount,
        ?NormalizedFilter $filter = null,
        ?string $store = null,
    ): self {
        return new self(
            store: $store ?? self::defaultStore(),
            collection: $collection,
            documentCount: $documentCount,
            filterCount: self::filterCount($filter),
        );
    }

    private static function defaultStore(): string
    {
        return (string) config('vector.default', 'pgvector');
    }

    private static function filterCount(?NormalizedFilter $filter): int
    {
        return $filter !== null ? count($filter->conditions) : 0;
    }
}
