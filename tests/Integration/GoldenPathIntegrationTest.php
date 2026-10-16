<?php

use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\Tests\Support\PrismIntegrationHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;

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
