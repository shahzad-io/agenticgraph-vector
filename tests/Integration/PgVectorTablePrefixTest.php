<?php

use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Stores\PgVector\PgVectorCollectionRegistry;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    $this->collection = 'prefix_'.uniqid();
    $this->hash = substr(hash('sha256', $this->collection), 0, 32);
    $this->stores = [];
});

afterEach(function () {
    foreach ($this->stores as $store) {
        try {
            if ($store->collectionExists($this->collection)) {
                $store->deleteCollection($this->collection);
            }
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }
});

function prefixStore(object $test, string $name, array $config): mixed
{
    config()->set("vector.stores.{$name}", [...config('vector.stores.pgvector'), ...$config]);
    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);

    return $test->stores[] = Vector::store($name);
}

function prefixTableExists(string $table): bool
{
    return (bool) DB::connection('pgvector')->scalar('SELECT to_regclass(?) IS NOT NULL', [$table]);
}

it('puts the prefix in the data, registry, alias and migration table names', function () {
    $store = prefixStore($this, 'prefixed', ['table_prefix' => 'tp_', 'registry_table' => null]);
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));

    expect(prefixTableExists("tp_vcol_{$this->hash}"))->toBeTrue()
        ->and(prefixTableExists("vcol_{$this->hash}"))->toBeFalse()
        ->and(prefixTableExists('tp_vector_collection_registry'))->toBeTrue()
        ->and(prefixTableExists('tp_vector_collection_registry_aliases'))->toBeTrue()
        ->and(prefixTableExists('tp_vector_collection_registry_migrations'))->toBeTrue()
        ->and(DB::connection('pgvector')->scalar('SELECT storage_identifier FROM tp_vector_collection_registry WHERE name = ?', [$this->collection]))->toBe("tp_vcol_{$this->hash}");
});

it('uses an explicit registry_table as written', function () {
    $store = prefixStore($this, 'prefixed_registry', ['table_prefix' => 'tq_', 'registry_table' => 'tq_explicit_registry']);
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));

    expect(prefixTableExists("tq_vcol_{$this->hash}"))->toBeTrue()
        ->and(prefixTableExists('tq_explicit_registry'))->toBeTrue()
        ->and(prefixTableExists('tq_vector_collection_registry'))->toBeFalse();
});

it('gives the same names as before with an empty prefix', function () {
    $store = prefixStore($this, 'unprefixed', ['table_prefix' => '', 'registry_table' => null]);
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));

    expect(prefixTableExists("vcol_{$this->hash}"))->toBeTrue()
        ->and(DB::connection('pgvector')->scalar('SELECT storage_identifier FROM vector_collection_registry WHERE name = ?', [$this->collection]))->toBe("vcol_{$this->hash}");
});

it('refuses a data table that this store has not registered and never reuses it', function () {
    $first = prefixStore($this, 'owner', ['table_prefix' => 'tr_', 'registry_table' => 'tr_registry_a']);
    $first->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));
    $second = prefixStore($this, 'intruder', ['table_prefix' => 'tr_', 'registry_table' => 'tr_registry_b']);

    expect(fn () => $second->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine')))
        ->toThrow(VectorQueryException::class, "table [tr_vcol_{$this->hash}] exists")
        ->and(fn () => $second->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine')))
        ->toThrow(VectorQueryException::class, 'table_prefix')
        ->and($second->collectionExists($this->collection))->toBeFalse()
        ->and($first->collectionExists($this->collection))->toBeTrue();
});

it('shares the collections of two stores with the same prefix and registry', function () {
    $first = prefixStore($this, 'shared_a', ['table_prefix' => 'ts_', 'registry_table' => null]);
    $first->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));
    $first->collection($this->collection)->upsert([Document::make('a', 'a', vector: [1.0, 0.0, 0.0])]);
    $second = prefixStore($this, 'shared_b', ['table_prefix' => 'ts_', 'registry_table' => null]);

    expect($second->collection($this->collection)->fetch(['a'])->count())->toBe(1);
});

it('validates the prefix as a Postgres identifier part that leaves room for every name', function (string $prefix) {
    expect(fn () => new PgVectorCollectionRegistry(DB::connection('pgvector'), 'r', $prefix))
        ->toThrow(VectorQueryException::class, 'Invalid pgvector table_prefix');
})->with(['1abc', 'Upper_', 'has-dash', 'semi;colon', str_repeat('a', PgVectorCollectionRegistry::MAX_TABLE_PREFIX + 1)]);

it('accepts a prefix of the longest length, and the longest index name fits', function () {
    $prefix = str_repeat('a', PgVectorCollectionRegistry::MAX_TABLE_PREFIX);
    $registry = new PgVectorCollectionRegistry(DB::connection('pgvector'), 'r', $prefix);

    expect(strlen($registry->storageIdentifier('x').'_embedding_idx'))->toBe(63);
});
