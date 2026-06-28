<?php

use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorInvalidMetadataException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped('Pgvector database is not available.');
    }

    $this->store = Vector::store('pgvector');
    $this->collection = 'articles_'.uniqid();
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

it('manages pgvector collection lifecycle', function () {
    $definition = new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine');

    $this->store->createCollection($definition);

    expect($this->store->collectionExists($this->collection))->toBeTrue()
        ->and($this->store->listCollections())->toContain($this->collection);

    $this->store->deleteCollection($this->collection);

    expect($this->store->collectionExists($this->collection))->toBeFalse()
        ->and($this->store->listCollections())->not->toContain($this->collection);
});

it('returns a healthy pgvector health check', function () {
    $result = $this->store->healthCheck();

    expect($result->healthy)->toBeTrue()
        ->and($result->latencyMs)->toBeGreaterThanOrEqual(0);
});

it('searches ranked results with topK and minScore using cosine similarity', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));

    $this->store->upsert($this->collection, [
        Document::make('a', 'alpha', vector: [1, 0, 0]),
        Document::make('b', 'beta', vector: [0.9, 0.1, 0]),
        Document::make('c', 'gamma', vector: [0, 1, 0]),
    ]);

    $results = $this->store->search(new SearchQuery(
        collection: $this->collection,
        vector: [1, 0, 0],
        topK: 2,
        minScore: 0.9,
    ));

    expect($results->count())->toBe(2)
        ->and($results->hits[0]->id)->toBe('a')
        ->and($results->hits[0]->score)->toBeGreaterThan($results->hits[1]->score)
        ->and($results->pluck('id'))->not->toContain('c');
});

it('translates normalized filters into jsonb constraints with bound parameters', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    $this->store->upsert($this->collection, [
        Document::make('en-billing', 'one', ['lang' => 'en', 'category' => 'billing'], [1, 0, 0]),
        Document::make('en-other', 'two', ['lang' => 'en', 'category' => 'other'], [1, 0, 0]),
        Document::make('fr-billing', 'three', ['lang' => 'fr', 'category' => 'billing'], [1, 0, 0]),
    ]);

    $filter = NormalizedFilter::from([
        ['field' => 'lang', 'operator' => FilterOperator::Equals, 'value' => 'en'],
        ['field' => 'category', 'operator' => FilterOperator::In, 'value' => ['billing', 'payments']],
    ]);

    $results = $this->store->search(new SearchQuery(
        collection: $this->collection,
        vector: [1, 0, 0],
        filter: $filter,
        topK: 10,
    ));

    expect($results->pluck('id'))->toBe(['en-billing']);
});

it('fetches documents by id', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    $this->store->upsert($this->collection, [
        Document::make('one', 'alpha', vector: [1, 0, 0]),
        Document::make('two', 'beta', vector: [0, 1, 0]),
    ]);

    $results = $this->store->fetch($this->collection, ['two']);

    expect($results->count())->toBe(1)
        ->and($results->first()?->content)->toBe('beta');
});

it('writes documents with precomputed vectors and validates metadata', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    $this->store->insert($this->collection, [
        Document::make('one', 'alpha', ['lang' => 'en'], [1, 0, 0]),
    ]);

    $this->store->upsert($this->collection, [
        Document::make('one', 'updated', ['lang' => 'en'], [1, 0, 0]),
    ], globalMetadata: ['source' => 'test']);

    $fetched = $this->store->fetch($this->collection, ['one']);

    expect($fetched->first()?->content)->toBe('updated')
        ->and($fetched->first()?->metadata)->toMatchArray(['lang' => 'en', 'source' => 'test']);

    expect(fn () => $this->store->insert($this->collection, [
        Document::make('bad', 'invalid', ['bad' => new stdClass], [1, 0, 0]),
    ]))->toThrow(VectorInvalidMetadataException::class);

    $this->store->delete($this->collection, 'one');

    expect($this->store->fetch($this->collection, ['one'])->count())->toBe(0);
});

it('deletes documents by normalized filter', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    $this->store->upsert($this->collection, [
        Document::make('keep', 'keep', ['status' => 'active'], [1, 0, 0]),
        Document::make('drop', 'drop', ['status' => 'archived'], [1, 0, 0]),
    ]);

    $this->store->deleteByFilter($this->collection, NormalizedFilter::from([
        ['field' => 'status', 'operator' => FilterOperator::Equals, 'value' => 'archived'],
    ]));

    $ids = $this->store->fetch($this->collection, ['keep', 'drop'])->pluck('id');

    expect($ids)->toBe(['keep']);
});

it('does not expose storage terminology in collection errors', function () {
    expect(fn () => $this->store->fetch('missing-collection', ['one']))
        ->toThrow(VectorCollectionNotFoundException::class, 'Collection [missing-collection] was not found.');
});
