<?php

use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scenario 2 — primary DB (sqlite) + separate pgvector connection.
 *
 * Proves vector operations stay on the configured vector connection and never
 * leak onto database.default (guards against a future default-connection fallback).
 */
beforeEach(function (): void {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    config()->set('database.default', 'primary');
    config()->set('database.connections.primary', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('database.connections.pgvector', PgvectorTestHelper::connectionConfig());
    config()->set('vector.stores.pgvector.connection', 'pgvector');

    DB::purge('primary');
    DB::purge('pgvector');

    app()->forgetInstance(VectorManager::class);
    app()->forgetInstance(EmbeddingsManager::class);
    Vector::clearResolvedInstance(VectorManager::class);

    $this->primaryConnection = (string) config('database.default');
    $this->vectorConnection = (string) config('vector.stores.pgvector.connection');
    $this->registryTable = (string) config('vector.stores.pgvector.registry_table', 'vector_collection_registry');
    $this->store = Vector::store('pgvector');
    $this->collection = 'scenario2_'.uniqid();
});

afterEach(function (): void {
    try {
        if (isset($this->store, $this->collection) && $this->store->collectionExists($this->collection)) {
            $this->store->deleteCollection($this->collection);
        }
    } catch (Throwable) {
        // Best-effort cleanup between tests.
    }
});

it('keeps vector registry and operations off the primary database connection', function (): void {
    expect(Schema::connection($this->primaryConnection)->hasTable($this->registryTable))->toBeFalse();

    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));

    expect(Schema::connection($this->primaryConnection)->hasTable($this->registryTable))->toBeFalse()
        ->and(Schema::connection($this->vectorConnection)->hasTable($this->registryTable))->toBeTrue()
        ->and($this->store->collectionExists($this->collection))->toBeTrue();

    $this->store->upsert($this->collection, [
        Document::make('doc-a', 'alpha content', ['source_id' => 101], [1, 0, 0]),
        Document::make('doc-b', 'beta content', ['source_id' => 202], [0.9, 0.1, 0]),
        Document::make('doc-c', 'gamma content', ['source_id' => 303], [0, 1, 0]),
    ]);

    $search = $this->store->search(new SearchQuery(
        collection: $this->collection,
        vector: [1, 0, 0],
        topK: 2,
    ));

    expect($search->count())->toBe(2)
        ->and($search->hits[0]->id)->toBe('doc-a')
        ->and($search->hits[0]->metadata)->toMatchArray(['source_id' => 101])
        ->and($search->hits[0]->score)->toBeGreaterThan($search->hits[1]->score);

    $fetched = $this->store->fetch($this->collection, ['doc-b']);

    expect($fetched->count())->toBe(1)
        ->and($fetched->first()?->content)->toBe('beta content')
        ->and($fetched->first()?->metadata)->toMatchArray(['source_id' => 202]);

    expect(Schema::connection($this->primaryConnection)->hasTable($this->registryTable))->toBeFalse()
        ->and(config('database.default'))->toBe('primary')
        ->and(config('vector.stores.pgvector.connection'))->toBe('pgvector');

    $this->store->deleteCollection($this->collection);

    expect($this->store->collectionExists($this->collection))->toBeFalse()
        ->and(Schema::connection($this->primaryConnection)->hasTable($this->registryTable))->toBeFalse();
});
