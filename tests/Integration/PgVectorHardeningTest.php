<?php

use AgenticGraph\Vector\Exceptions\VectorExtensionMissingException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Stores\PgVector\PgVectorExtensionGuard;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

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

function hardeningStorage(string $collection): string
{
    return 'vcol_'.substr(hash('sha256', $collection), 0, 32);
}

function hardeningIndexDefinition(string $collection): ?string
{
    $storage = hardeningStorage($collection);

    return DB::connection('pgvector')->selectOne(
        'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?',
        [$storage, "{$storage}_embedding_idx"],
    )?->indexdef;
}

/**
 * @return list<string>
 */
function hardeningQueries(callable $callback): array
{
    $executed = [];
    DB::connection('pgvector')->listen(function ($query) use (&$executed): void {
        $executed[] = $query->sql;
    });

    $callback();

    return $executed;
}

function hardeningFreshStore(array $config = [])
{
    config()->set('vector.stores.pgvector', array_merge(config('vector.stores.pgvector'), $config));
    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);

    return Vector::store('pgvector');
}

it('creates an hnsw index by default', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));

    expect(hardeningIndexDefinition($this->collection))->toContain('USING hnsw')
        ->toContain("m='16'")
        ->toContain("ef_construction='64'");
});

it('creates an hnsw index with the definition index options', function () {
    $this->store->createCollection(new CollectionDefinition(
        $this->collection,
        dimensions: 3,
        index: 'hnsw',
        indexOptions: ['m' => 8, 'ef_construction' => 32],
    ));

    expect(hardeningIndexDefinition($this->collection))->toContain('USING hnsw')
        ->toContain("m='8'")
        ->toContain("ef_construction='32'");
});

it('compares the index type and options on idempotent create', function () {
    $definition = new CollectionDefinition($this->collection, dimensions: 3, index: 'hnsw', indexOptions: ['m' => 8, 'ef_construction' => 32]);
    $this->store->createCollection($definition);
    $this->store->createCollection($definition);

    expect(fn () => $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3)))
        ->toThrow(VectorQueryException::class, 'already exists with a different definition')
        ->and(fn () => $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, index: 'ivfflat')))
        ->toThrow(VectorQueryException::class, 'already exists with a different definition');
});

it('matches a pre-B3 hnsw index without options to a default definition', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    $storage = hardeningStorage($this->collection);
    DB::connection('pgvector')->statement("DROP INDEX {$storage}_embedding_idx");
    DB::connection('pgvector')->statement("CREATE INDEX {$storage}_embedding_idx ON {$storage} USING hnsw (embedding vector_cosine_ops)");

    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    expect(fn () => $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, indexOptions: ['m' => 8])))
        ->toThrow(VectorQueryException::class, 'already exists with a different definition');
});

it('keeps the requested ivfflat lists across the clamp, upserts and rebuildIndex', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, index: 'ivfflat', indexOptions: ['lists' => 3]));

    expect(hardeningIndexDefinition($this->collection))->toContain('USING ivfflat')->toContain("lists='1'");

    $builder = $this->store->collection($this->collection);
    $builder->upsert([
        Document::make('one', 'alpha', vector: [1, 0, 0]),
        Document::make('two', 'beta', vector: [0, 1, 0]),
    ]);

    // An upsert no longer rebuilds: the empty collection's one list stays until rebuildIndex().
    expect(hardeningIndexDefinition($this->collection))->toContain("lists='1'");
    Vector::rebuildIndex($this->collection, 'pgvector');
    expect(hardeningIndexDefinition($this->collection))->toContain("lists='2'");

    $builder->upsert([
        Document::make('three', 'gamma', vector: [0, 0, 1]),
        Document::make('four', 'delta', vector: [0.7, 0.7, 0]),
        Document::make('five', 'epsilon', vector: [0.2, 0.8, 0]),
    ]);
    Vector::rebuildIndex($this->collection, 'pgvector');

    expect(hardeningIndexDefinition($this->collection))->toContain("lists='3'");

    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, index: 'ivfflat', indexOptions: ['lists' => 3]));

    expect(fn () => $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, index: 'ivfflat', indexOptions: ['lists' => 4])))
        ->toThrow(VectorQueryException::class, 'already exists with a different definition')
        ->and(fn () => $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, index: 'ivfflat')))
        ->toThrow(VectorQueryException::class, 'already exists with a different definition');
});

it('rejects invalid index options before creating storage', function (?string $index, array $options, string $message) {
    expect(fn () => $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, index: $index, indexOptions: $options)))
        ->toThrow(VectorQueryException::class, $message);

    expect($this->store->collectionExists($this->collection))->toBeFalse();
})->with([
    'unknown index' => ['flat', [], 'Index [flat] is not supported by pgvector'],
    'ivfflat option on hnsw' => ['hnsw', ['lists' => 10], 'Index option [lists] is not valid for hnsw'],
    'hnsw option on ivfflat' => ['ivfflat', ['m' => 8], 'Index option [m] is not valid for ivfflat'],
    'm too small' => [null, ['m' => 1], 'Index option [m] must be between 2 and 100'],
    'ef_construction below 2m' => [null, ['m' => 16, 'ef_construction' => 20], 'at least twice [m]'],
    'lists too large' => ['ivfflat', ['lists' => 40000], 'Index option [lists] must be between 1 and 32768'],
    'not an integer' => [null, ['m' => '16'], 'Index option [m] must be an integer'],
]);

it('checks the extension and the registry table once per store instance', function () {
    $store = hardeningFreshStore();
    $isExtensionCheck = fn (string $sql): bool => str_contains($sql, 'pg_extension');
    $isRegistryCheck = fn (string $sql): bool => str_contains($sql, "c.relname = 'vector_collection_registry'");

    $first = hardeningQueries(fn () => $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3)));

    $later = hardeningQueries(function () use ($store): void {
        $store->collection($this->collection)->upsert([Document::make('one', 'alpha', vector: [1, 0, 0])]);
        $store->collection($this->collection)->fetch(['one']);
        $store->collection($this->collection)->usingVector([1, 0, 0])->get();
        $store->collectionExists($this->collection);
    });

    expect(array_filter($first, $isExtensionCheck))->toHaveCount(1)
        ->and(array_filter($first, $isRegistryCheck))->not->toBeEmpty()
        ->and(array_filter($later, $isExtensionCheck))->toBe([])
        ->and(array_filter($later, $isRegistryCheck))->toBe([]);
});

it('skips the extension check when verify_extension is false', function () {
    $store = hardeningFreshStore(['verify_extension' => false]);

    $queries = hardeningQueries(fn () => $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3)));

    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'pg_extension')))->toBe([])
        ->and($store->collectionExists($this->collection))->toBeTrue();
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

it('stores empty metadata as an object and merges into rows written with an empty array', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));
    $builder = $this->store->collection($this->collection);
    $builder->upsert([Document::make('new', 'new', [], [1, 0, 0])]);

    $storage = hardeningStorage($this->collection);
    DB::connection('pgvector')->insert("INSERT INTO {$storage} (id, content, embedding, metadata) VALUES ('old', 'old', '[1,0,0]', '[]'::jsonb)");

    $builder->updateMetadata(['new' => ['a' => 1], 'old' => ['a' => 1]]);

    expect(DB::connection('pgvector')->scalar("SELECT jsonb_typeof(metadata) FROM {$storage} WHERE id = 'new'"))->toBe('object')
        ->and($builder->fetch(['old'])->first()?->metadata)->toBe(['a' => 1])
        ->and($builder->fetch(['new'])->first()?->metadata)->toBe(['a' => 1]);
});

it('adds index_options to a registry created without it and round-trips the requested index', function () {
    $table = 'vector_registry_pre_b3_'.uniqid();
    DB::connection('pgvector')->statement("CREATE TABLE {$table} (
        name VARCHAR(255) PRIMARY KEY,
        dimensions INTEGER NOT NULL,
        metric VARCHAR(32) NOT NULL,
        storage_identifier VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    try {
        $store = hardeningFreshStore(['registry_table' => $table]);
        $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, index: 'ivfflat', indexOptions: ['lists' => 7]));
        $hnsw = $this->collection.'_hnsw';
        $store->createCollection(new CollectionDefinition($hnsw, dimensions: 3, indexOptions: ['m' => 8]));

        $stored = fn (string $name): array => json_decode(
            (string) DB::connection('pgvector')->scalar("SELECT index_options FROM {$table} WHERE name = ?", [$name]),
            true,
        );

        expect(DB::connection('pgvector')->getSchemaBuilder()->hasColumn($table, 'index_options'))->toBeTrue()
            ->and($stored($this->collection))->toBe(['type' => 'ivfflat', 'options' => ['lists' => 7]])
            ->and($stored($hnsw))->toBe(['type' => 'hnsw', 'options' => ['m' => 8, 'ef_construction' => 64]]);

        $store->collection($this->collection)->upsert(array_map(
            fn (int $i) => Document::make("d{$i}", "d{$i}", vector: [1, $i, 0]),
            range(1, 9),
        ));
        $store->rebuildIndex($this->collection);

        expect(hardeningIndexDefinition($this->collection))->toContain("lists='7'");

        $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, index: 'ivfflat', indexOptions: ['lists' => 7]));
        $store->createCollection(new CollectionDefinition($hnsw, dimensions: 3, indexOptions: ['m' => 8]));

        expect(fn () => $store->createCollection(new CollectionDefinition($hnsw, dimensions: 3, indexOptions: ['m' => 9])))
            ->toThrow(VectorQueryException::class, 'already exists with a different definition');

        $store->deleteCollection($this->collection);
        $store->deleteCollection($hnsw);
    } finally {
        DB::connection('pgvector')->statement("DROP TABLE IF EXISTS {$table}");
    }
});

it('rebuilds the index concurrently under a temporary name and an advisory lock, then renames it', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, index: 'ivfflat', indexOptions: ['lists' => 4]));
    $this->store->collection($this->collection)->upsert(array_map(fn (int $i) => Document::make("d{$i}", "d{$i}", vector: [1, $i, 0]), range(1, 6)));
    $storage = hardeningStorage($this->collection);

    $executed = hardeningQueries(fn () => Vector::rebuildIndex($this->collection, 'pgvector'));
    $ddl = array_values(array_filter($executed, fn (string $sql): bool => preg_match('/INDEX|advisory/', $sql) === 1));

    expect($ddl)->toBe([
        'SELECT pg_try_advisory_lock(hashtext(?))',
        "DROP INDEX CONCURRENTLY IF EXISTS {$storage}_embedding_idx_rebuild",
        "CREATE INDEX CONCURRENTLY {$storage}_embedding_idx_rebuild ON {$storage} USING ivfflat (embedding vector_cosine_ops) WITH (lists = 4)",
        "DROP INDEX CONCURRENTLY IF EXISTS {$storage}_embedding_idx",
        "ALTER INDEX {$storage}_embedding_idx_rebuild RENAME TO {$storage}_embedding_idx",
        'SELECT pg_advisory_unlock(hashtext(?))',
    ])->and(hardeningIndexDefinition($this->collection))->toContain("lists='4'");
});

it('refuses a rebuild while another session rebuilds the same collection', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));
    config()->set('database.connections.pgvector_probe', config('database.connections.pgvector'));
    $probe = DB::connection('pgvector_probe');
    $probe->select('SELECT pg_advisory_lock(hashtext(?))', ['vector_index_rebuild:'.hardeningStorage($this->collection)]);

    try {
        expect(fn () => Vector::rebuildIndex($this->collection, 'pgvector'))->toThrow(VectorQueryException::class, 'already being rebuilt');
        $this->artisan('vector:index:rebuild', ['collection' => $this->collection, '--store' => 'pgvector'])
            ->expectsOutputToContain('already being rebuilt')
            ->assertFailed();
    } finally {
        $probe->select('SELECT pg_advisory_unlock_all()');
        DB::purge('pgvector_probe');
    }

    Vector::rebuildIndex($this->collection, 'pgvector');
    expect(hardeningIndexDefinition($this->collection))->toContain('USING hnsw');
});

it('restores the index a killed rebuild dropped before its rename', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, index: 'ivfflat', indexOptions: ['lists' => 2]));
    $this->store->collection($this->collection)->upsert(array_map(fn (int $i) => Document::make("d{$i}", "d{$i}", vector: [1, $i, 0]), range(1, 3)));
    $storage = hardeningStorage($this->collection);
    DB::connection('pgvector')->statement("ALTER INDEX {$storage}_embedding_idx RENAME TO {$storage}_embedding_idx_rebuild");

    Vector::rebuildIndex($this->collection, 'pgvector');

    expect(hardeningIndexDefinition($this->collection))->toContain("lists='2'")
        ->and(DB::connection('pgvector')->scalar('SELECT to_regclass(?)', ["{$storage}_embedding_idx_rebuild"]))->toBeNull();
});

it('refuses to rebuild inside a transaction, and on a store without an index', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));

    expect(fn () => DB::connection('pgvector')->transaction(fn () => Vector::rebuildIndex($this->collection, 'pgvector')))
        ->toThrow(VectorQueryException::class, 'inside a database transaction')
        ->and(fn () => Vector::rebuildIndex($this->collection, 'memory'))->toThrow(VectorUnsupportedOperationException::class);
});

it('rebuilds through vector:index:rebuild and names a missing collection', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, index: 'ivfflat', indexOptions: ['lists' => 2]));
    $this->store->collection($this->collection)->upsert(array_map(fn (int $i) => Document::make("d{$i}", "d{$i}", vector: [1, $i, 0]), range(1, 5)));

    $this->artisan('vector:index:rebuild', ['collection' => $this->collection, '--store' => 'pgvector'])
        ->expectsOutputToContain("Rebuilt the index of [{$this->collection}]")
        ->assertSuccessful();
    $this->artisan('vector:index:rebuild', ['collection' => 'missing_'.uniqid()])->assertFailed();

    expect(hardeningIndexDefinition($this->collection))->toContain("lists='2'");
});

it('drops its temporary index and names maintenance_work_mem when the build runs out of memory', function () {
    $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: 1536, index: 'ivfflat', indexOptions: ['lists' => 100]));
    $vector = fn (int $i): array => array_map(fn (int $d): float => sin($i * $d), range(1, 1536));
    $this->store->upsert($this->collection, array_map(fn (int $i) => Document::make("d{$i}", "d{$i}", vector: $vector($i)), range(1, 400)));
    $storage = hardeningStorage($this->collection);
    DB::connection('pgvector')->statement("SET maintenance_work_mem = '1MB'");

    try {
        expect(fn () => Vector::rebuildIndex($this->collection, 'pgvector'))->toThrow(VectorQueryException::class, 'maintenance_work_mem');
    } finally {
        DB::connection('pgvector')->statement('RESET maintenance_work_mem');
    }

    expect(DB::connection('pgvector')->scalar('SELECT to_regclass(?)', ["{$storage}_embedding_idx_rebuild"]))->toBeNull()
        ->and(hardeningIndexDefinition($this->collection))->toContain("lists='1'");
});
