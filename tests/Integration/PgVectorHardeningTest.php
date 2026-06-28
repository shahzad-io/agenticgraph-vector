<?php

use AgenticGraph\Vector\Exceptions\VectorExtensionMissingException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Stores\PgVector\PgVectorExtensionGuard;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use Illuminate\Database\Connection;

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    $this->store = Vector::store('pgvector');
    $this->collection = 'hardening_'.uniqid();
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

it('uses the fluent builder for document writes without positional collection args', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    Vector::store('pgvector')
        ->collection($this->collection)
        ->upsert([
            Document::make('one', 'alpha', vector: [1, 0, 0]),
        ]);

    $result = Vector::store('pgvector')
        ->collection($this->collection)
        ->fetch(['one']);

    expect($result->first()?->content)->toBe('alpha');
});

it('creates an hnsw index by default', function () {
    $definition = new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine');
    $this->store->createCollection($definition);

    $storage = 'vcol_'.substr(hash('sha256', $this->collection), 0, 32);

    $index = DB::connection('pgvector')->selectOne(
        'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?',
        [$storage, "{$storage}_embedding_idx"],
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('USING hnsw');
});

it('is idempotent when creating an identical collection', function () {
    $definition = new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine');

    $this->store->createCollection($definition);
    $this->store->createCollection($definition);

    expect($this->store->collectionExists($this->collection))->toBeTrue();
});

it('throws when recreating a collection with a conflicting definition', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));

    expect(fn () => $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 4, metric: 'cosine')))
        ->toThrow(VectorQueryException::class, 'already exists with a different definition');
});

it('rejects invalid dimensions before creating storage', function () {
    expect(fn () => $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 0)))
        ->toThrow(VectorQueryException::class, 'dimensions must be at least 1');

    expect($this->store->collectionExists($this->collection))->toBeFalse();
});

it('remains recoverable after a failed createCollection attempt', function () {
    expect(fn () => $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 0)))
        ->toThrow(VectorQueryException::class);

    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    expect($this->store->collectionExists($this->collection))->toBeTrue();
});

it('throws a friendly typed exception when the pgvector extension is missing', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('scalar')
        ->with("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'")
        ->andReturn(0);
    $connection->shouldReceive('statement')
        ->with('CREATE EXTENSION IF NOT EXISTS vector')
        ->andThrow(new RuntimeException('permission denied'));
    $connection->shouldReceive('scalar')
        ->with("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'")
        ->andReturn(0);

    $guard = new PgVectorExtensionGuard($connection, attemptInstall: true);

    expect(fn () => $guard->ensureAvailable())
        ->toThrow(VectorExtensionMissingException::class, 'CREATE EXTENSION vector;');
});
