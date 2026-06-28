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
        public ?SearchQuery $searchQuery = null,
        public int $filterCount = 0,
    ) {}

    public static function forSearch(SearchQuery $query, ?string $store = null): self
    {
        return new self(
            store: $store ?? (string) config('vector.default', 'pgvector'),
            collection: $query->collection,
            searchQuery: $query,
            filterCount: $query->filter !== null ? count($query->filter->conditions) : 0,
        );
    }

    public static function forCollection(string $collection, ?string $store = null): self
    {
        return new self(
            store: $store ?? (string) config('vector.default', 'pgvector'),
            collection: $collection,
        );
    }

    public static function forWrite(
        string $collection,
        int $documentCount,
        ?NormalizedFilter $filter = null,
        ?string $store = null,
    ): self {
        return new self(
            store: $store ?? (string) config('vector.default', 'pgvector'),
            collection: $collection,
            documentCount: $documentCount,
            filterCount: $filter !== null ? count($filter->conditions) : 0,
        );
    }
}
