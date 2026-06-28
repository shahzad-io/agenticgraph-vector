<?php

use AgenticGraph\Vector\Builder\Builder;
use AgenticGraph\Vector\Contracts\DocumentStoreContract;
use AgenticGraph\Vector\Contracts\SearchStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;

it('throws a clear unsupported operation before delete by filter on incapable stores', function () {
    $store = new class implements DocumentStoreContract, SearchStoreContract
    {
        public function search(SearchQuery $query): SearchResult
        {
            return SearchResult::empty();
        }

        public function capabilities(): StoreCapabilities
        {
            return new StoreCapabilities(deleteByFilter: false);
        }

        public function fetch(string $collection, array $ids): SearchResult
        {
            return SearchResult::empty();
        }

        public function insert(string $collection, array $documents, ?callable $onProgress = null): void {}

        public function upsert(string $collection, array $documents, array $globalMetadata = [], ?callable $onProgress = null): void {}

        public function delete(string $collection, array|string|int $ids): void {}

        public function deleteByFilter(string $collection, NormalizedFilter $filter): void {}

        public function batchSize(): int
        {
            return 100;
        }
    };

    $embeddings = Mockery::mock(EmbeddingsManager::class);

    $builder = new Builder($store, 'articles', $embeddings);

    expect(fn () => $builder->deleteByFilter(new NormalizedFilter([])))
        ->toThrow(VectorUnsupportedOperationException::class, 'Delete by filter is not supported by this store.');
});
