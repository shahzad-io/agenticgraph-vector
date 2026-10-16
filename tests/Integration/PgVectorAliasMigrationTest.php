<?php

use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    $this->registry = 'vector_registry_b5_'.uniqid();
    config()->set('vector.stores.pgvector.registry_table', $this->registry);
    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);
    $this->collection = 'aliasmig_'.uniqid();
});

afterEach(function () {
    $connection = DB::connection('pgvector');

    foreach ($connection->select("SELECT storage_identifier FROM {$this->registry}") as $row) {
        $connection->statement("DROP TABLE IF EXISTS {$row->storage_identifier}");
    }

    foreach (['', '_aliases', '_migrations'] as $suffix) {
        $connection->statement("DROP TABLE IF EXISTS {$this->registry}{$suffix}");
    }
});

/**
 * @return list<Document>
 */
function aliasMigrationDocuments(int $count): array
{
    return array_map(fn (int $i): Document => Document::make("d{$i}", "document {$i}", ['i' => $i], [1.0, (float) $i, 0.0]), range(1, $count));
}

it('upgrades a registry created before B5; its collections have no recorded embeddings, so migrate needs a mode', function () {
    DB::connection('pgvector')->statement("CREATE TABLE {$this->registry} (
        name VARCHAR(255) PRIMARY KEY,
        dimensions INTEGER NOT NULL,
        metric VARCHAR(32) NOT NULL,
        storage_identifier VARCHAR(255) NOT NULL UNIQUE,
        index_options JSONB NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));
    $store->collection($this->collection)->upsert(aliasMigrationDocuments(3));
    DB::connection('pgvector')->update("UPDATE {$this->registry} SET embedding = NULL");

    $schema = DB::connection('pgvector')->getSchemaBuilder();
    expect($schema->hasColumn($this->registry, 'embedding'))->toBeTrue()
        ->and($schema->hasTable("{$this->registry}_aliases"))->toBeTrue()
        ->and($schema->hasTable("{$this->registry}_migrations"))->toBeTrue()
        ->and(fn () => Vector::migrate($this->collection, new CollectionDefinition($this->collection, dimensions: 3)))
        ->toThrow(VectorQueryException::class, 'no recorded embedding model');

    $state = Vector::migrate($this->collection, new CollectionDefinition($this->collection, dimensions: 3), mode: 'copy');

    expect($state->phase)->toBe('switched')
        ->and($store->listAliases())->toBe([$this->collection => "{$this->collection}__v2"])
        ->and($store->collection($this->collection)->usingVector([1.0, 2.0, 0.0])->take(1)->get()->first()?->id)->toBe('d2')
        // The adopted collection keeps its table: the one named after the alias.
        ->and(DB::connection('pgvector')->scalar("SELECT storage_identifier FROM {$this->registry} WHERE name = ?", ["{$this->collection}__v1"]))
        ->toBe('vcol_'.substr(hash('sha256', $this->collection), 0, 32));
});

it('holds the alias row while a write is open, so a switch waits for it', function () {
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));
    $store->collection($this->collection)->upsert(aliasMigrationDocuments(2));
    Vector::migrate($this->collection, new CollectionDefinition($this->collection, dimensions: 3));

    $config = PgvectorTestHelper::connectionConfig();
    $other = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $other->exec("SET lock_timeout = '300ms'");
    $switch = fn () => $other->exec("UPDATE {$this->registry}_aliases SET updated_at = CURRENT_TIMESTAMP WHERE alias = '{$this->collection}'");

    foreach ([
        fn () => $store->collection($this->collection)->upsert([Document::make('x', 'x', vector: [1.0, 0.0, 0.0])]),
        fn () => $store->collection($this->collection)->delete('d1'),
        fn () => $store->collection($this->collection)->where('i', 2)->delete(),
        fn () => $store->collection($this->collection)->updateMetadata(['d2' => ['i' => 3]]),
    ] as $write) {
        DB::connection('pgvector')->transaction(function () use ($write, $switch): void {
            $write();

            expect($switch)->toThrow(PDOException::class, 'lock timeout');
        });
    }

    // A search takes no lock.
    DB::connection('pgvector')->transaction(function () use ($store, $switch): void {
        $store->collection($this->collection)->usingVector([1.0, 0.0, 0.0])->get();

        expect($switch())->toBe(1);
    });
});

it('rebuilds an IVFFlat target index once the copy ends', function () {
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));
    $store->collection($this->collection)->upsert(aliasMigrationDocuments(3));

    Vector::migrate($this->collection, new CollectionDefinition($this->collection, dimensions: 3, index: 'ivfflat', indexOptions: ['lists' => 7]));

    $storage = DB::connection('pgvector')->scalar("SELECT storage_identifier FROM {$this->registry} WHERE name = ?", ["{$this->collection}__v2"]);

    expect(DB::connection('pgvector')->scalar('SELECT pg_get_indexdef(to_regclass(?))', ["{$storage}_embedding_idx"]))->toContain("lists='3'");
});
