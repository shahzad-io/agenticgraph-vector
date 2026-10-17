<?php

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Providers\DeterministicEmbeddingProvider;
use AgenticGraph\Vector\Testing\CountingEmbeddingProvider;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Database\QueryException;
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

it('holds off a migration final pass while a write to a plain collection is embedding', function (bool $alias) {
    Vector::store('pgvector')->createCollection(new CollectionDefinition($this->collection, dimensions: 3));
    $storage = DB::connection('pgvector')->scalar("SELECT storage_identifier FROM {$this->registry} WHERE name = ?", [$this->collection]);

    if ($alias) {
        Vector::migrate($this->collection, new CollectionDefinition($this->collection, dimensions: 3), mode: 'copy');
        $storage = DB::connection('pgvector')->scalar("SELECT storage_identifier FROM {$this->registry} WHERE name = ?", [Vector::store('pgvector')->listAliases()[$this->collection]]);
    }

    // A second session tries the final pass's locks (alias row FOR UPDATE, then the table) while the write embeds.
    config()->set('database.connections.pgvector_probe', config('database.connections.pgvector'));
    $blocked = null;
    $embeddings = new CountingEmbeddingProvider(new DeterministicEmbeddingProvider(dimensions: 3));
    [$aliases, $alias] = [$this->registry.'_aliases', $this->collection];
    // Manager::extend() rebinds the closure to the manager, so capture values, not $this.
    app(EmbeddingsManager::class)->extend('probe', function () use ($embeddings, $storage, $aliases, $alias, &$blocked) {
        return new class($embeddings, $storage, $aliases, $alias, $blocked) implements EmbeddingProviderContract
        {
            public function __construct(private CountingEmbeddingProvider $inner, private string $storage, private string $aliases, private string $alias, private ?bool &$blocked) {}

            public function embed(string $text): array
            {
                return $this->inner->embed($text);
            }

            public function embedBatch(array $texts): array
            {
                $probe = DB::connection('pgvector_probe');
                $probe->beginTransaction();

                try {
                    $probe->select("SELECT alias FROM {$this->aliases} WHERE alias = ? FOR UPDATE NOWAIT", [$this->alias]);
                    $probe->statement("LOCK TABLE {$this->storage} IN SHARE ROW EXCLUSIVE MODE NOWAIT");
                    $this->blocked = false;
                } catch (QueryException $e) {
                    $this->blocked = str_contains($e->getMessage(), '55P03');
                } finally {
                    $probe->rollBack();
                }

                return $this->inner->embedBatch($texts);
            }

            public function dimensions(): int
            {
                return 3;
            }

            public function model(): string
            {
                return 'probe';
            }
        };
    });
    config()->set('vector.embeddings.default', 'probe');
    DB::connection('pgvector')->table($this->registry)->update(['embedding' => null]);

    Vector::store('pgvector')->collection($this->collection)->upsert([Document::make('a', 'written while a migration would finish')]);

    expect($blocked)->toBeTrue();
})->with(['plain collection' => false, 'alias (guard: B5 alias lock)' => true]);
