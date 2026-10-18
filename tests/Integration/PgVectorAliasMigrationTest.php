<?php

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Providers\DeterministicEmbeddingProvider;
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

    $other = PgvectorTestHelper::pdo();
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

/**
 * Register embedding provider $name ($dimensions wide) that calls $onEmbed before each batch.
 */
function aliasMigrationProbeProvider(string $name, int $dimensions, Closure $onEmbed): void
{
    // Manager::extend() rebinds the closure to the manager, so capture values, not $this.
    app(EmbeddingsManager::class)->extend($name, fn () => new class(new DeterministicEmbeddingProvider(dimensions: $dimensions, modelName: $name), $onEmbed) implements EmbeddingProviderContract
    {
        public function __construct(private DeterministicEmbeddingProvider $inner, private Closure $onEmbed) {}

        public function embed(string $text): array
        {
            return $this->embedBatch([$text])[0];
        }

        public function embedBatch(array $texts): array
        {
            ($this->onEmbed)($texts);

            return $this->inner->embedBatch($texts);
        }

        public function dimensions(): int
        {
            return $this->inner->dimensions();
        }

        public function model(): string
        {
            return $this->inner->model();
        }
    });
}

/**
 * Whether a second session could take a migration final pass's locks (alias row FOR UPDATE, then the table) right now.
 */
function aliasMigrationFinalPassBlocked(string $aliases, string $alias, ?string $storage = null): bool
{
    config()->set('database.connections.pgvector_probe', config('database.connections.pgvector'));
    $probe = DB::connection('pgvector_probe');
    $probe->beginTransaction();

    try {
        $probe->select("SELECT alias FROM {$aliases} WHERE alias = ? FOR UPDATE NOWAIT", [$alias]);

        if ($storage !== null) {
            $probe->statement("LOCK TABLE {$storage} IN SHARE ROW EXCLUSIVE MODE NOWAIT");
        }

        return false;
    } catch (QueryException $e) {
        return str_contains($e->getMessage(), '55P03') ? true : throw $e;
    } finally {
        $probe->rollBack();
    }
}

it('embeds a write before taking its locks, so a migration final pass never waits on an embedding call', function (bool $alias) {
    Vector::store('pgvector')->createCollection(new CollectionDefinition($this->collection, dimensions: 3));
    $storage = DB::connection('pgvector')->scalar("SELECT storage_identifier FROM {$this->registry} WHERE name = ?", [$this->collection]);

    if ($alias) {
        Vector::migrate($this->collection, new CollectionDefinition($this->collection, dimensions: 3), mode: 'copy');
        $storage = DB::connection('pgvector')->scalar("SELECT storage_identifier FROM {$this->registry} WHERE name = ?", [Vector::store('pgvector')->listAliases()[$this->collection]]);
    }

    $blocked = null;
    [$aliases, $name] = [$this->registry.'_aliases', $this->collection];
    aliasMigrationProbeProvider('probe', 3, function () use (&$blocked, $aliases, $name, $storage): void {
        $blocked = aliasMigrationFinalPassBlocked($aliases, $name, $storage);
    });
    config()->set('vector.embeddings.default', 'probe');
    DB::connection('pgvector')->table($this->registry)->update(['embedding' => null]);

    Vector::store('pgvector')->collection($this->collection)->upsert([Document::make('a', 'written while a migration would finish')]);

    expect($blocked)->toBeFalse()
        ->and(Vector::store('pgvector')->collection($this->collection)->fetch(['a'])->first()?->id)->toBe('a');
})->with(['plain collection' => false, 'alias' => true]);

it('embeds a write again for the new target when a switch lands while it embeds', function () {
    // The first narrow embedding switches the alias to __v3 (wide) from another session.
    $switched = false;
    [$aliases, $name] = [$this->registry.'_aliases', $this->collection];
    aliasMigrationProbeProvider('narrow', 3, function () use (&$switched, $aliases, $name): void {
        if (! $switched) {
            config()->set('database.connections.pgvector_probe', config('database.connections.pgvector'));
            DB::connection('pgvector_probe')->statement("SET lock_timeout = '300ms'");
            DB::connection('pgvector_probe')->update("UPDATE {$aliases} SET collection = ? WHERE alias = ?", ["{$name}__v3", $name]);
            $switched = true;
        }
    });
    aliasMigrationProbeProvider('wide', 4, fn () => null);
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, embeddings: 'narrow'));
    Vector::migrate($this->collection, new CollectionDefinition($this->collection, dimensions: 3), mode: 'copy');
    $store->createCollection(new CollectionDefinition("{$this->collection}__v3", dimensions: 4, embeddings: 'wide'));

    $store->collection($this->collection)->upsert([Document::make('a', 'written across a switch')]);

    expect($switched)->toBeTrue()
        ->and($store->countDocuments("{$this->collection}__v2"))->toBe(0)
        ->and(DB::connection('pgvector')->scalar('SELECT vector_dims(embedding) FROM '.DB::connection('pgvector')->scalar("SELECT storage_identifier FROM {$this->registry} WHERE name = ?", ["{$this->collection}__v3"])." WHERE id = 'a'"))->toBe(4);
});

it('runs another unlocked pass for writes the final pass would embed, and embeds inside the lock only on the last pass', function (int $passes) {
    config()->set('vector.migration.catch_up_max_passes', $passes);
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));
    $store->collection($this->collection)->upsert(aliasMigrationDocuments(3));

    // Each target embedding call records whether the final pass holds the alias lock, and the first two
    // write a new source row (during the copy, then during the catch-up pass), so the final pass finds one.
    $calls = [];
    [$aliases, $name] = [$this->registry.'_aliases', $this->collection];
    aliasMigrationProbeProvider('target', 3, function () use (&$calls, $aliases, $name, $store): void {
        $calls[] = aliasMigrationFinalPassBlocked($aliases, $name);

        if (count($calls) <= 2) {
            $store->collection($name)->upsert([Document::make('w'.count($calls), 'written during the migration', vector: [0.0, 1.0, 0.0])]);
        }
    });

    $state = Vector::migrate($this->collection, new CollectionDefinition($this->collection, dimensions: 3, embeddings: 'target'), mode: 'reembed');

    expect($state->phase)->toBe('switched')
        ->and($store->collection($this->collection)->fetch(['w1', 'w2'])->count())->toBe(2)
        ->and($calls)->toBe($passes === 1 ? [false, false, true] : [false, false, false]);
})->with(['two passes' => 2, 'one pass (guard: the last pass embeds inside the lock)' => 1]);
