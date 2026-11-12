<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores;

use AgenticGraph\Vector\Concerns\InteractsWithBuilder;
use AgenticGraph\Vector\Contracts\AliasStoreContract;
use AgenticGraph\Vector\Contracts\IndexStoreContract;
use AgenticGraph\Vector\Contracts\MigrationStoreContract;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\Enums\FieldType;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Exceptions\VectorInvalidFilterException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\Tracing\VectorTraceContext;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\HealthCheckResult;
use AgenticGraph\Vector\ValueObjects\IdPage;
use AgenticGraph\Vector\ValueObjects\MigrationState;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;
use AgenticGraph\Vector\ValueObjects\UpdateMetadataResult;

/**
 * What Vector::store() returns: the configured store, with every operation (document, search and
 * collection management) going through TraceSpan under the store's configured name. Alias calls throw
 * VectorUnsupportedOperationException unless the store supports Aliases, migration calls unless it supports
 * Migrations, and rebuildIndex() unless it supports RebuildIndex.
 *
 * describeCollection() is cached per name for the life of this instance (one request or job: the manager is
 * scoped), so a search makes no extra store call for it. A create, delete, alias change or migration step through
 * this instance clears the cache; a change made by another process shows from the next request on. A queued ingest batch is an `upsert` span like any other.
 *
 * The store-neutral checks run here, so every store gives the same answer: a zero vector under cosine is refused
 * on write and on search (VectorQueryException); a filter on a field that is not declared needs UndeclaredFilters
 * (VectorUnsupportedOperationException); on a declared field, an operator its FieldType does not allow or a value
 * of another type throws VectorInvalidFilterException.
 */
final class InstrumentedStore implements IndexStoreContract, MigrationStoreContract, VectorStoreContract
{
    use InteractsWithBuilder;

    /** @var array<string, array{collection: string, dimensions: int, metric: string, index: array{type: string, options: array<string, scalar>}|null, embedding: array{provider: string, model: string, dimensions: int}|null, keyword: array{languages: array<string, string>, language_field: string}|null, filterable: array<string, string>}> */
    private array $descriptions = [];

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
        $this->assertVectorUsable($query->collection, [$query->vector]);
        $this->assertFilterAllowed($query->collection, $query->filter);

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
        $this->assertVectorUsable($collection, array_map(static fn ($document): ?array => $document->vector, $documents));
        TraceSpan::operation(
            'insert',
            fn () => $this->store->insert($collection, $documents, $onProgress),
            VectorTraceContext::forWrite($collection, count($documents), store: $this->name),
        );
    }

    public function upsert(string $collection, array $documents, array $globalMetadata = [], ?callable $onProgress = null): void
    {
        $this->assertVectorUsable($collection, array_map(static fn ($document): ?array => $document->vector, $documents));
        TraceSpan::operation(
            'upsert',
            fn () => $this->store->upsert($collection, $documents, $globalMetadata, $onProgress),
            VectorTraceContext::forWrite($collection, count($documents), store: $this->name),
        );
    }

    public function rebuildIndex(string $collection): void
    {
        if (! $this->store instanceof IndexStoreContract || ! $this->store->capabilities()->supports(StoreCapability::RebuildIndex)) {
            throw new VectorUnsupportedOperationException("Store [{$this->name}] has no index to rebuild.");
        }

        $store = $this->store;

        TraceSpan::operation(
            'rebuild_index',
            fn () => $store->rebuildIndex($collection),
            VectorTraceContext::forCollection($collection, $this->name),
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
        $this->assertFilterAllowed($collection, $filter);
        TraceSpan::operation(
            'delete_by_filter',
            fn () => $this->store->deleteByFilter($collection, $filter),
            VectorTraceContext::forCollection($collection, $this->name, $filter),
        );
    }

    public function listIds(string $collection, ?NormalizedFilter $filter = null, ?string $cursor = null, int $limit = 100): IdPage
    {
        $this->assertFilterAllowed($collection, $filter);

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
        $this->descriptions = [];
        TraceSpan::operation(
            'create_collection',
            fn () => $this->store->createCollection($definition),
            VectorTraceContext::forCollection($definition->name, $this->name),
        );
    }

    public function deleteCollection(string $name): void
    {
        $this->descriptions = [];
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

    public function describeCollection(string $name): array
    {
        return $this->descriptions[$name] ??= $this->store->describeCollection($name);
    }

    public function listAliases(): array
    {
        return TraceSpan::operation(
            'list_aliases',
            fn (): array => $this->aliasStore()->listAliases(),
            VectorTraceContext::forCollection('', $this->name),
        );
    }

    public function deleteAlias(string $alias): void
    {
        $this->descriptions = [];
        TraceSpan::operation(
            'delete_alias',
            fn () => $this->aliasStore()->deleteAlias($alias),
            VectorTraceContext::forCollection($alias, $this->name),
        );
    }

    public function pointAlias(string $alias, string $collection): void
    {
        $this->descriptions = [];
        $this->aliasStore()->pointAlias($alias, $collection);
    }

    public function adoptCollection(string $name, string $physical): void
    {
        $this->descriptions = [];
        $this->migrationStore()->adoptCollection($name, $physical);
    }

    public function countDocuments(string $collection): int
    {
        return $this->aliasStore()->countDocuments($collection);
    }

    public function migration(string $alias): ?MigrationState
    {
        return $this->migrationStore()->migration($alias);
    }

    public function saveMigration(MigrationState $state): void
    {
        $this->descriptions = [];
        $this->migrationStore()->saveMigration($state);
    }

    public function migrateBatch(MigrationState $state, int $limit): MigrationState
    {
        $this->descriptions = [];

        return $this->migrationStore()->migrateBatch($state, $limit);
    }

    public function catchUpMigration(MigrationState $state): int
    {
        $this->descriptions = [];

        return $this->migrationStore()->catchUpMigration($state);
    }

    public function finishMigration(MigrationState $state, int $threshold, bool $embedInLock = true): MigrationState
    {
        $this->descriptions = [];

        return $this->migrationStore()->finishMigration($state, $threshold, $embedInLock);
    }

    private function aliasStore(): AliasStoreContract
    {
        if (! $this->store instanceof AliasStoreContract || ! $this->store->capabilities()->supports(StoreCapability::Aliases)) {
            throw new VectorUnsupportedOperationException("Aliases are not supported by store [{$this->name}].");
        }

        return $this->store;
    }

    private function migrationStore(): MigrationStoreContract
    {
        if (! $this->store instanceof MigrationStoreContract || ! $this->store->capabilities()->supports(StoreCapability::Migrations)) {
            throw new VectorUnsupportedOperationException("Migrations are not supported by store [{$this->name}].");
        }

        return $this->store;
    }

    /**
     * A zero vector has no direction, so cosine cannot score it. Describes the collection only when one is present.
     *
     * @param  list<list<float>|null>  $vectors
     */
    private function assertVectorUsable(string $collection, array $vectors): void
    {
        foreach ($vectors as $vector) {
            if ($vector !== null && $vector !== [] && array_filter($vector) === []
                && $this->describeCollection($collection)['metric'] === 'cosine') {
                throw new VectorQueryException("A zero vector cannot be scored under cosine (collection [{$collection}]).");
            }
        }
    }

    private function assertFilterAllowed(string $collection, ?NormalizedFilter $filter): void
    {
        if ($filter === null || $filter->conditions === []) {
            return;
        }

        $declared = $this->describeCollection($collection)['filterable'];
        $undeclared = $this->store->capabilities()->supports(StoreCapability::UndeclaredFilters);

        foreach ($filter->conditions as $condition) {
            if (! isset($declared[$condition->field])) {
                if (! $undeclared) {
                    throw new VectorUnsupportedOperationException(
                        "Field [{$condition->field}] is not declared filterable on collection [{$collection}], and store [{$this->name}] filters declared fields only. Declare it in CollectionDefinition::\$filterable.",
                    );
                }

                continue;
            }

            $type = FieldType::from($declared[$condition->field]);

            if (! $type->allows($condition->operator)) {
                throw new VectorInvalidFilterException("The {$condition->operator->value} filter is not allowed on field [{$condition->field}], declared {$type->value}.");
            }

            // contains takes one element of the list.
            $valueType = $type === FieldType::KeywordList ? FieldType::Keyword : $type;

            foreach (is_array($condition->value) ? $condition->value : [$condition->value] as $value) {
                if (! $valueType->accepts($value)) {
                    throw new VectorInvalidFilterException("Filter value for field [{$condition->field}] must be {$type->value}, got ".get_debug_type($value).'.');
                }
            }
        }
    }
}
