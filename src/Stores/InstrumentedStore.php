<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores;

use AgenticGraph\Vector\Concerns\InteractsWithBuilder;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\Tracing\VectorTraceContext;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\HealthCheckResult;
use AgenticGraph\Vector\ValueObjects\IdPage;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;
use AgenticGraph\Vector\ValueObjects\UpdateMetadataResult;

/**
 * What Vector::store() returns: the configured store, with every operation (document, search and
 * collection management) going through TraceSpan under the store's configured name.
 */
final class InstrumentedStore implements VectorStoreContract
{
    use InteractsWithBuilder;

    public function __construct(
        private readonly string $name,
        private readonly VectorStoreContract $store,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function search(SearchQuery $query): SearchResult
    {
        return TraceSpan::operation(
            'search',
            fn (): SearchResult => $this->store->search($query),
            VectorTraceContext::forSearch($query, $this->name),
        );
    }

    public function capabilities(): StoreCapabilities
    {
        return $this->store->capabilities();
    }

    public function fetch(string $collection, array $ids): SearchResult
    {
        return TraceSpan::operation(
            'fetch',
            fn (): SearchResult => $this->store->fetch($collection, $ids),
            VectorTraceContext::forCollection($collection, $this->name),
        );
    }

    public function insert(string $collection, array $documents, ?callable $onProgress = null): void
    {
        TraceSpan::operation(
            'insert',
            fn () => $this->store->insert($collection, $documents, $onProgress),
            VectorTraceContext::forWrite($collection, count($documents), store: $this->name),
        );
    }

    public function upsert(string $collection, array $documents, array $globalMetadata = [], ?callable $onProgress = null): void
    {
        TraceSpan::operation(
            'upsert',
            fn () => $this->store->upsert($collection, $documents, $globalMetadata, $onProgress),
            VectorTraceContext::forWrite($collection, count($documents), store: $this->name),
        );
    }

    public function delete(string $collection, array|string|int $ids): void
    {
        TraceSpan::operation(
            'delete',
            fn () => $this->store->delete($collection, $ids),
            VectorTraceContext::forCollection($collection, $this->name),
        );
    }

    public function deleteByFilter(string $collection, NormalizedFilter $filter): void
    {
        TraceSpan::operation(
            'delete_by_filter',
            fn () => $this->store->deleteByFilter($collection, $filter),
            VectorTraceContext::forCollection($collection, $this->name, $filter),
        );
    }

    public function listIds(string $collection, ?NormalizedFilter $filter = null, ?string $cursor = null, int $limit = 100): IdPage
    {
        return TraceSpan::operation(
            'list_ids',
            fn (): IdPage => $this->store->listIds($collection, $filter, $cursor, $limit),
            VectorTraceContext::forCollection($collection, $this->name, $filter),
        );
    }

    public function updateMetadata(string $collection, array $metadataById, bool $merge = true): UpdateMetadataResult
    {
        return TraceSpan::operation(
            'update_metadata',
            fn (): UpdateMetadataResult => $this->store->updateMetadata($collection, $metadataById, $merge),
            VectorTraceContext::forWrite($collection, count($metadataById), store: $this->name),
        );
    }

    public function batchSize(): int
    {
        return $this->store->batchSize();
    }

    public function createCollection(CollectionDefinition $definition): void
    {
        TraceSpan::operation(
            'create_collection',
            fn () => $this->store->createCollection($definition),
            VectorTraceContext::forCollection($definition->name, $this->name),
        );
    }

    public function deleteCollection(string $name): void
    {
        TraceSpan::operation(
            'delete_collection',
            fn () => $this->store->deleteCollection($name),
            VectorTraceContext::forCollection($name, $this->name),
        );
    }

    public function collectionExists(string $name): bool
    {
        return TraceSpan::operation(
            'collection_exists',
            fn (): bool => $this->store->collectionExists($name),
            VectorTraceContext::forCollection($name, $this->name),
        );
    }

    public function listCollections(): array
    {
        return TraceSpan::operation(
            'list_collections',
            fn (): array => $this->store->listCollections(),
            VectorTraceContext::forCollection('', $this->name),
        );
    }

    public function healthCheck(): HealthCheckResult
    {
        return TraceSpan::operation(
            'health_check',
            fn (): HealthCheckResult => $this->store->healthCheck(),
            VectorTraceContext::forCollection('', $this->name),
        );
    }
}
