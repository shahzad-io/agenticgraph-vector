<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Testing;

use AgenticGraph\Vector\Concerns\InteractsWithBuilder;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\HealthCheckResult;
use AgenticGraph\Vector\ValueObjects\IdPage;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;
use AgenticGraph\Vector\ValueObjects\UpdateMetadataResult;

/**
 * Wraps a store and records the name of each call that reaches it (capabilities() and batchSize() excluded:
 * they are configuration, not store calls), to assert how many calls an operation makes.
 */
final class CountingStore implements VectorStoreContract
{
    use InteractsWithBuilder;

    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        private readonly VectorStoreContract $inner,
    ) {}

    public function search(SearchQuery $query): SearchResult
    {
        $this->calls[] = 'search';

        return $this->inner->search($query);
    }

    public function capabilities(): StoreCapabilities
    {
        return $this->inner->capabilities();
    }

    public function fetch(string $collection, array $ids): SearchResult
    {
        $this->calls[] = 'fetch';

        return $this->inner->fetch($collection, $ids);
    }

    public function insert(string $collection, array $documents, ?callable $onProgress = null): void
    {
        $this->calls[] = 'insert';
        $this->inner->insert($collection, $documents, $onProgress);
    }

    public function upsert(string $collection, array $documents, array $globalMetadata = [], ?callable $onProgress = null): void
    {
        $this->calls[] = 'upsert';
        $this->inner->upsert($collection, $documents, $globalMetadata, $onProgress);
    }

    public function delete(string $collection, array|string|int $ids): void
    {
        $this->calls[] = 'delete';
        $this->inner->delete($collection, $ids);
    }

    public function deleteByFilter(string $collection, NormalizedFilter $filter): void
    {
        $this->calls[] = 'deleteByFilter';
        $this->inner->deleteByFilter($collection, $filter);
    }

    public function listIds(string $collection, ?NormalizedFilter $filter = null, ?string $cursor = null, int $limit = 100): IdPage
    {
        $this->calls[] = 'listIds';

        return $this->inner->listIds($collection, $filter, $cursor, $limit);
    }

    public function updateMetadata(string $collection, array $metadataById, bool $merge = true): UpdateMetadataResult
    {
        $this->calls[] = 'updateMetadata';

        return $this->inner->updateMetadata($collection, $metadataById, $merge);
    }

    public function batchSize(): int
    {
        return $this->inner->batchSize();
    }

    public function createCollection(CollectionDefinition $definition): void
    {
        $this->calls[] = 'createCollection';
        $this->inner->createCollection($definition);
    }

    public function deleteCollection(string $name): void
    {
        $this->calls[] = 'deleteCollection';
        $this->inner->deleteCollection($name);
    }

    public function collectionExists(string $name): bool
    {
        $this->calls[] = 'collectionExists';

        return $this->inner->collectionExists($name);
    }

    public function listCollections(): array
    {
        $this->calls[] = 'listCollections';

        return $this->inner->listCollections();
    }

    public function healthCheck(): HealthCheckResult
    {
        $this->calls[] = 'healthCheck';

        return $this->inner->healthCheck();
    }

    public function describeCollection(string $name): array
    {
        $this->calls[] = 'describeCollection';

        return $this->inner->describeCollection($name);
    }
}
