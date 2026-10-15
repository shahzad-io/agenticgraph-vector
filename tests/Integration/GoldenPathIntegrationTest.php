<?php

use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\Tests\Support\PrismIntegrationHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\SearchQuery;

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    $this->store = Vector::store('pgvector');
    $this->collection = 'golden_'.uniqid();
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

it('runs the golden path end-to-end with deterministic embeddings and pgvector', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('billing', 'Billing and payments guide', ['lang' => 'en']),
            Document::make('cooking', 'Cooking recipes and kitchen tips', ['lang' => 'en']),
            Document::make('billing-fr', 'Guide de facturation', ['lang' => 'fr']),
        ]);

    $results = Vector::store('pgvector')
        ->collection($this->collection)
        ->where('lang', 'en')
        ->similarTo('Billing and payments guide')
        ->take(5)
        ->get();

    expect($results->count())->toBeGreaterThan(0)
        ->and($results->first()?->id)->toBe('billing');
});

it('uses usingVector without embedding while similarTo embeds query text', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    $queryVector = Embeddings::using('deterministic')->embed('target query');

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('match', 'target query', vector: $queryVector),
            Document::make('other', 'something else', vector: Embeddings::using('deterministic')->embed('other')),
        ]);

    $fromSimilarTo = Vector::store('pgvector')
        ->collection($this->collection)
        ->similarTo('target query')
        ->take(1)
        ->get();

    $fromVector = Vector::store('pgvector')
        ->collection($this->collection)
        ->usingVector($queryVector)
        ->take(1)
        ->get();

    expect($fromSimilarTo->first()?->id)->toBe('match')
        ->and($fromVector->first()?->id)->toBe('match');
});

it('defaults collection dimensions from the embedding provider', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection));

    $providerDimensions = Embeddings::using('deterministic')->dimensions();

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('doc', 'dimension alignment check'),
        ]);

    $vector = Embeddings::using('deterministic')->embed('dimension alignment check');

    expect($providerDimensions)->toBe(count($vector))
        ->and($this->store->search(new SearchQuery(
            collection: $this->collection,
            vector: $vector,
            topK: 1,
        ))->count())->toBe(1);
});

it('auto-embeds document content on write when no vector is supplied', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->insert([
            Document::make('auto', 'auto embedded content', ['lang' => 'en']),
        ]);

    $results = Vector::store('pgvector')
        ->collection($this->collection)
        ->similarTo('auto embedded content')
        ->take(1)
        ->get();

    expect($results->first()?->id)->toBe('auto');
});

it('runs a gated real Prism embedding round-trip when configured', function () {
    if (! PrismIntegrationHelper::isAvailable()) {
        $this->markTestSkipped(PrismIntegrationHelper::skipReason());
    }

    app()->forgetInstance(EmbeddingsManager::class);
    PrismIntegrationHelper::useOpenAi();

    $collection = 'prism_'.$this->collection;
    $store = Vector::store('pgvector');
    $dimensions = Embeddings::using('prism')->dimensions();

    $store->createCollection(new CollectionDefinition($collection, dimensions: $dimensions));

    $store->upsert($collection, [
        Document::make('doc', 'Real embedding integration test document', ['lang' => 'en']),
    ]);

    $results = Vector::store('pgvector')
        ->collection($collection)
        ->similarTo('Real embedding integration test document')
        ->take(1)
        ->get();

    expect($results->count())->toBe(1)
        ->and($results->first()?->id)->toBe('doc');

    $store->deleteCollection($collection);
})->group('prism-integration');
