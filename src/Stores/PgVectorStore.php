<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores;

use AgenticGraph\Vector\Concerns\InteractsWithBuilder;
use AgenticGraph\Vector\Contracts\CollectionStoreContract;
use AgenticGraph\Vector\Contracts\DocumentStoreContract;
use AgenticGraph\Vector\Contracts\SearchStoreContract;
use AgenticGraph\Vector\Support\ThrowsWhenNotImplemented;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\HealthCheckResult;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;

final class PgVectorStore implements CollectionStoreContract, DocumentStoreContract, SearchStoreContract
{
    use InteractsWithBuilder;
    use ThrowsWhenNotImplemented;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function search(SearchQuery $query): SearchResult
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function capabilities(): StoreCapabilities
    {
        return StoreCapabilities::pgvector();
    }

    public function fetch(array $ids): SearchResult
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function insert(array $documents): void
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function upsert(array $documents, array $globalMetadata = []): void
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function delete(array|string|int $ids): void
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function deleteByFilter(NormalizedFilter $filter): void
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function batchSize(): int
    {
        return (int) ($this->config['batch_size'] ?? config('vector.batch.size', 100));
    }

    public function createCollection(CollectionDefinition $definition): void
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function deleteCollection(string $name): void
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function collectionExists(string $name): bool
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function listCollections(): array
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function healthCheck(): HealthCheckResult
    {
        $this->throwNotImplemented(__METHOD__);
    }
}
