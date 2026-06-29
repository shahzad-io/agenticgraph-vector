<?php

use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    $this->store = Vector::store('pgvector');
});

afterEach(function () {
    config()->set('vector.stores.pgvector', array_merge(config('vector.stores.pgvector'), [
        'index' => 'hnsw',
        'metric' => 'cosine',
        'ivfflat_lists' => 100,
    ]));
    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);

    foreach ($this->collectionsToDelete ?? [] as $collection) {
        try {
            if ($this->store->collectionExists($collection)) {
                $this->store->deleteCollection($collection);
            }
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }
});

it('uses the store config metric when CollectionDefinition metric is omitted', function () {
    $collection = 'metric_fallback_'.uniqid();
    $this->collectionsToDelete = [$collection];

    config()->set('vector.stores.pgvector.metric', 'l2');
    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);
    $this->store = Vector::store('pgvector');

    $this->store->createCollection(new CollectionDefinition($collection, dimensions: 3));

    $metric = DB::connection('pgvector')->scalar(
        'SELECT metric FROM vector_collection_registry WHERE name = ?',
        [$collection],
    );

    expect($metric)->toBe('l2');
});

it('issues SET LOCAL session tuning when search options are supplied', function () {
    $collection = 'search_options_'.uniqid();
    $this->collectionsToDelete = [$collection];

    $this->store->createCollection(new CollectionDefinition($collection, dimensions: 3, metric: 'cosine'));
    $this->store->upsert($collection, [
        Document::make('a', 'alpha', vector: [1, 0, 0]),
        Document::make('b', 'beta', vector: [0.9, 0.1, 0]),
    ]);

    $executed = [];

    DB::connection('pgvector')->listen(function ($query) use (&$executed): void {
        $executed[] = $query->sql;
    });

    $this->store->search(new SearchQuery(
        collection: $collection,
        vector: [1, 0, 0],
        topK: 1,
        options: [
            'hnsw.ef_search' => 64,
            'ivfflat.probes' => 8,
        ],
    ));

    expect(collect($executed)->contains(fn (string $sql): bool => str_contains($sql, 'hnsw.ef_search = 64')))->toBeTrue()
        ->and(collect($executed)->contains(fn (string $sql): bool => str_contains($sql, 'ivfflat.probes = 8')))->toBeTrue();
});

it('clamps ivfflat lists to the document count when the corpus is smaller than the configured lists', function () {
    $collection = 'ivfflat_lists_'.uniqid();
    $this->collectionsToDelete = [$collection];

    config()->set('vector.stores.pgvector', array_merge(config('vector.stores.pgvector'), [
        'index' => 'ivfflat',
        'ivfflat_lists' => 100,
    ]));
    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);
    $this->store = Vector::store('pgvector');

    $this->store->createCollection(new CollectionDefinition($collection, dimensions: 3, metric: 'cosine'));
    $this->store->upsert($collection, [
        Document::make('one', 'alpha', vector: [1, 0, 0]),
        Document::make('two', 'beta', vector: [0, 1, 0]),
        Document::make('three', 'gamma', vector: [0, 0, 1]),
        Document::make('four', 'delta', vector: [0.7, 0.7, 0]),
        Document::make('five', 'epsilon', vector: [0.2, 0.8, 0]),
    ]);

    $storage = 'vcol_'.substr(hash('sha256', $collection), 0, 32);

    $index = DB::connection('pgvector')->selectOne(
        'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?',
        [$storage, "{$storage}_embedding_idx"],
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain("lists='5'");
});
