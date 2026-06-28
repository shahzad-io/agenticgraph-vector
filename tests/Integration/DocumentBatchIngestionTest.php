<?php

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Exceptions\VectorInvalidMetadataException;
use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Providers\DeterministicEmbeddingProvider;
use AgenticGraph\Vector\Tests\Support\CountingEmbeddingProvider;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\VectorManager;

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped('Pgvector database is not available.');
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

it('chunks large upserts by batchSize with one embedBatch call per chunk', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert(makeBatchDocuments(250));

    expect($this->countingProvider->embedBatchCallCount)->toBe(3);

    $fetched = Vector::store('pgvector')
        ->collection($this->collection)
        ->fetch(array_map(static fn (int $index): string => "doc-{$index}", range(0, 249)));

    expect($fetched->count())->toBe(250);

    $search = Vector::store('pgvector')
        ->collection($this->collection)
        ->similarTo('batch ingestion document 42')
        ->take(1)
        ->get();

    expect($search->first()?->id)->toBe('doc-42');
});

it('reports onProgress after each chunk completes', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    $progress = [];

    Vector::store('pgvector')
        ->collection($this->collection)
        ->onProgress(function (int $done, int $total) use (&$progress): void {
            $progress[] = [$done, $total];
        })
        ->upsert(makeBatchDocuments(250));

    expect($progress)->toBe([
        [100, 250],
        [200, 250],
        [250, 250],
    ]);
});

it('works without an onProgress callback', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert(makeBatchDocuments(250));

    expect(Vector::store('pgvector')->collection($this->collection)->fetch(['doc-0'])->count())->toBe(1);
});

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

it('persists prior chunks when a later chunk fails validation', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    $documents = makeBatchDocuments(250);

    $documents[150] = Document::make(
        id: 'doc-150',
        content: 'invalid metadata document',
        metadata: ['bad' => new stdClass],
    );

    expect(fn () => Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert($documents))
        ->toThrow(VectorInvalidMetadataException::class);

    $firstChunk = Vector::store('pgvector')
        ->collection($this->collection)
        ->fetch(['doc-0', 'doc-99']);

    $failedChunk = Vector::store('pgvector')
        ->collection($this->collection)
        ->fetch(['doc-100', 'doc-150']);

    expect($firstChunk->count())->toBe(2)
        ->and($failedChunk->count())->toBe(0);
});

it('embeds only content-only documents while preserving precomputed vectors in a batch', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    $precomputed = Embeddings::using('deterministic')->embed('precomputed vector document');

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('precomputed', 'precomputed vector document', vector: $precomputed),
            Document::make('embedded', 'auto embedded batch document'),
        ]);

    expect($this->countingProvider->embedBatchCallCount)->toBe(1);

    $results = Vector::store('pgvector')
        ->collection($this->collection)
        ->similarTo('precomputed vector document')
        ->take(1)
        ->get();

    expect($results->first()?->id)->toBe('precomputed');
});

it('uses a single chunk for small upserts under batchSize', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert(makeBatchDocuments(5));

    expect($this->countingProvider->embedBatchCallCount)->toBe(1)
        ->and(Vector::store('pgvector')->collection($this->collection)->fetch(['doc-4'])->count())->toBe(1);
});

it('chunks large inserts the same way as upserts', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->insert(makeBatchDocuments(250, prefix: 'insert'));

    expect($this->countingProvider->embedBatchCallCount)->toBe(3)
        ->and(Vector::store('pgvector')->collection($this->collection)->fetch(['insert-249'])->count())->toBe(1);
});
