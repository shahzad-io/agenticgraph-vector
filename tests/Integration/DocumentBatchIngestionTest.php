<?php

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Providers\DeterministicEmbeddingProvider;
use AgenticGraph\Vector\Testing\CountingEmbeddingProvider;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\VectorManager;

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    $this->countingProvider = new CountingEmbeddingProvider(new DeterministicEmbeddingProvider(dimensions: 8));

    app()->instance(EmbeddingsManager::class, new class($this->countingProvider) extends EmbeddingsManager
    {
        public function __construct(private readonly EmbeddingProviderContract $provider) {}

        public function using(?string $name = null): EmbeddingProviderContract
        {
            return $this->provider;
        }

        public function driver($driver = null): EmbeddingProviderContract
        {
            return $this->provider;
        }
    });

    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);

    $this->store = Vector::store('pgvector');
    $this->collection = 'batch_'.uniqid();

    config()->set('vector.batch.size', 100);
    config()->set('vector.stores.pgvector.batch_size', 100);
});

afterEach(function () {
    try {
        if ($this->store->collectionExists($this->collection)) {
            $this->store->deleteCollection($this->collection);
        }
    } catch (Throwable) {
        // Best-effort cleanup between tests.
    }
});

function makeBatchDocuments(int $count, string $prefix = 'doc'): array
{
    $documents = [];

    for ($index = 0; $index < $count; $index++) {
        $documents[] = Document::make(
            id: "{$prefix}-{$index}",
            content: "batch ingestion document {$index}",
            metadata: ['batch' => 'test', 'index' => $index],
        );
    }

    return $documents;
}

it('respects a config override for batch size when chunking', function () {
    config()->set('vector.stores.pgvector', array_merge(config('vector.stores.pgvector'), [
        'batch_size' => 50,
    ]));
    config()->set('vector.batch.size', 50);

    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);
    $this->store = Vector::store('pgvector');
    $this->countingProvider->embedBatchCallCount = 0;

    expect($this->store->batchSize())->toBe(50);

    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert(makeBatchDocuments(250));

    expect($this->countingProvider->embedBatchCallCount)->toBe(5);
});
