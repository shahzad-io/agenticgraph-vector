<?php

use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped('Pgvector database is not available.');
    }

    $this->store = Vector::store('pgvector');
    $this->collection = 'v4_'.uniqid();
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

it('runs the full fluent search chain end-to-end against pgvector', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('billing-en', 'Billing and payments guide', [
                'lang' => 'en',
                'category' => 'billing',
                'published' => true,
            ]),
            Document::make('payments-en', 'Payments processing overview', [
                'lang' => 'en',
                'category' => 'payments',
                'published' => true,
            ]),
            Document::make('billing-fr', 'Guide de facturation', [
                'lang' => 'fr',
                'category' => 'billing',
                'published' => true,
            ]),
            Document::make('draft-en', 'Draft billing notes', [
                'lang' => 'en',
                'category' => 'billing',
                'published' => false,
            ]),
        ]);

    $results = Vector::store('pgvector')
        ->collection($this->collection)
        ->where('lang', 'en')
        ->where('published', true)
        ->whereIn('category', ['billing', 'payments'])
        ->similarTo('Billing and payments guide')
        ->take(2)
        ->minScore(-1.0)
        ->get();

    expect($results->count())->toBe(2)
        ->and($results->pluck('id'))->toContain('billing-en')
        ->and($results->pluck('id'))->not->toContain('billing-fr', 'draft-en')
        ->and($results->hits[0]->score)->toBeGreaterThanOrEqual($results->hits[1]->score);
});

it('filters boolean and numeric metadata with type-consistent jsonb comparisons', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    $this->store->upsert($this->collection, [
        Document::make('published', 'published doc', ['published' => true, 'count' => 5], [1, 0, 0]),
        Document::make('unpublished', 'unpublished doc', ['published' => false, 'count' => 10], [1, 0, 0]),
        Document::make('other-count', 'other count doc', ['published' => true, 'count' => 5], [1, 0, 0]),
    ]);

    $publishedOnly = Vector::store('pgvector')
        ->collection($this->collection)
        ->where('published', true)
        ->usingVector([1, 0, 0])
        ->take(10)
        ->get();

    expect($publishedOnly->pluck('id'))->toEqualCanonicalizing(['published', 'other-count']);

    $countFive = Vector::store('pgvector')
        ->collection($this->collection)
        ->where('count', 5)
        ->usingVector([1, 0, 0])
        ->take(10)
        ->get();

    expect($countFive->pluck('id'))->toEqualCanonicalizing(['published', 'other-count']);
});

it('returns search hit metadata written at ingest time', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('doc', 'metadata round trip content', [
                'lang' => 'en',
                'source' => 'api',
                'published' => true,
                'count' => 42,
            ]),
        ]);

    $hit = Vector::store('pgvector')
        ->collection($this->collection)
        ->where('lang', 'en')
        ->similarTo('metadata round trip content')
        ->take(1)
        ->get()
        ->first();

    expect($hit)->not->toBeNull()
        ->and($hit->metadata)->toMatchArray([
            'lang' => 'en',
            'source' => 'api',
            'published' => true,
            'count' => 42,
        ]);
});

it('supports result ergonomics on real pgvector search hits', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    $this->store->upsert($this->collection, [
        Document::make('a', 'alpha content', ['tier' => 'a'], [1, 0, 0]),
        Document::make('b', 'beta content', ['tier' => 'b'], [0.9, 0.1, 0]),
        Document::make('c', 'gamma content', ['tier' => 'c'], [0, 1, 0]),
    ]);

    $results = Vector::store('pgvector')
        ->collection($this->collection)
        ->usingVector([1, 0, 0])
        ->take(3)
        ->get();

    expect($results->toContext('|'))->toContain('alpha content')
        ->and($results->filterByScore(0.5)->count())->toBeGreaterThan(0)
        ->and($results->averageScore())->toBeGreaterThan(0.0);
});

it('returns a clean empty SearchResult when minScore excludes all hits', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    $this->store->upsert($this->collection, [
        Document::make('a', 'alpha', vector: [1, 0, 0]),
        Document::make('b', 'beta', vector: [0, 1, 0]),
    ]);

    $results = Vector::store('pgvector')
        ->collection($this->collection)
        ->usingVector([1, 0, 0])
        ->minScore(1.01)
        ->take(10)
        ->get();

    expect($results->count())->toBe(0)
        ->and($results->hits)->toBe([])
        ->and($results->first())->toBeNull();
});

it('deletes documents fluently by id and by filter via the builder', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('keep', 'keep', ['status' => 'active'], [1, 0, 0]),
            Document::make('drop', 'drop', ['status' => 'archived'], [1, 0, 0]),
            Document::make('remove', 'remove', ['status' => 'archived'], [1, 0, 0]),
        ]);

    Vector::store('pgvector')
        ->collection($this->collection)
        ->delete('remove');

    Vector::store('pgvector')
        ->collection($this->collection)
        ->where('status', 'archived')
        ->delete();

    $remaining = Vector::store('pgvector')
        ->collection($this->collection)
        ->fetch(['keep', 'drop', 'remove']);

    expect($remaining->pluck('id'))->toBe(['keep']);
});

it('deletes documents with explicit filter sugar matching the PRD', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('keep', 'keep', ['status' => 'active'], [1, 0, 0]),
            Document::make('drop', 'drop', ['status' => 'archived'], [1, 0, 0]),
        ]);

    Vector::store('pgvector')
        ->collection($this->collection)
        ->delete(filter: ['status' => 'archived']);

    $remaining = Vector::store('pgvector')
        ->collection($this->collection)
        ->fetch(['keep', 'drop']);

    expect($remaining->pluck('id'))->toBe(['keep']);
});

it('deletes documents fluently via deleteByFilter with a NormalizedFilter', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('keep', 'keep', ['status' => 'active'], [1, 0, 0]),
            Document::make('drop', 'drop', ['status' => 'archived'], [1, 0, 0]),
        ]);

    Vector::store('pgvector')
        ->collection($this->collection)
        ->deleteByFilter(NormalizedFilter::from([
            ['field' => 'status', 'operator' => FilterOperator::Equals, 'value' => 'archived'],
        ]));

    $remaining = Vector::store('pgvector')
        ->collection($this->collection)
        ->fetch(['keep', 'drop']);

    expect($remaining->pluck('id'))->toBe(['keep']);
});

it('throws a clear error when get is called without a query vector', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 8));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('doc', 'content'),
        ]);

    expect(fn () => Vector::store('pgvector')
        ->collection($this->collection)
        ->where('lang', 'en')
        ->get())
        ->toThrow(VectorQueryException::class, 'A query is required: call similarTo(text) or usingVector(vector) before get().');
});

it('prefers delete by id when ids are provided even if filter conditions are present', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('one', 'one', ['status' => 'active'], [1, 0, 0]),
            Document::make('two', 'two', ['status' => 'archived'], [1, 0, 0]),
        ]);

    Vector::store('pgvector')
        ->collection($this->collection)
        ->where('status', 'archived')
        ->delete('one');

    $remaining = Vector::store('pgvector')
        ->collection($this->collection)
        ->fetch(['one', 'two']);

    expect($remaining->pluck('id'))->toContain('two')
        ->and($remaining->pluck('id'))->not->toContain('one');
});
