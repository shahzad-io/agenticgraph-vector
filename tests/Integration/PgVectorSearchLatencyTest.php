<?php

use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Support\Facades\DB;

// Spec section 12 (L1 to L8): the index is used on small collections (in a hybrid search, by its dense list), the
// vector is bound once, the registry lookup of a search is cached and serves reads only.

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    $this->collection = 'latency_'.uniqid();
});

afterEach(function () {
    try {
        $store = Vector::store('pgvector');

        foreach ([$this->collection, "{$this->collection}_b"] as $name) {
            if ($store->collectionExists($name)) {
                $store->deleteCollection($name);
            }
        }

        $store->deleteAlias("{$this->collection}_alias");
    } catch (Throwable) {
        // Best-effort cleanup between tests.
    }
});

function latencyStore(array $config = [])
{
    config()->set('vector.stores.pgvector', array_merge(config('vector.stores.pgvector'), $config));
    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);

    return Vector::store('pgvector');
}

/**
 * @return list<float>
 */
function latencyVector(int $dimensions): array
{
    return array_map(static fn (): float => mt_rand(-1000, 1000) / 1000, range(1, $dimensions));
}

/**
 * The SciFact shape: 5,183 rows of 1,536 dimensions with 1,500 characters of content. The vectors sit in TOAST,
 * which the planner does not cost, so it picks an exact scan over the HNSW index. Filled in SQL, then indexed
 * once, for speed.
 */
function latencyLargeCollection($store, string $name, bool $keyword = false): void
{
    $store->createCollection(new CollectionDefinition($name, dimensions: 1536, metric: 'cosine', keyword: $keyword));
    $storage = 'vcol_'.substr(hash('sha256', $name), 0, 32);
    $connection = DB::connection('pgvector');
    $connection->select('SELECT setseed(0.12)');
    $connection->statement("DROP INDEX {$storage}_embedding_idx");
    $connection->statement("INSERT INTO {$storage} (id, content, metadata, embedding)
        SELECT 'd' || i, repeat(md5(i::text), 47), jsonb_build_object('group', i % 4),
               (SELECT array_agg(random() - 0.5) FROM generate_series(1, 1536) g WHERE g > -i)::vector
        FROM generate_series(1, 5183) i");
    $store->rebuildIndex($name);
    $connection->statement("ANALYZE {$storage}");
}

/**
 * Runs the search inside an outer transaction, so its SET LOCAL settings are still on, and explains its statement.
 *
 * @return array{plan: string, enable_seqscan: string}
 */
function latencyPlan(callable $search): array
{
    $connection = DB::connection('pgvector');
    $statements = [];
    $connection->listen(function ($query) use (&$statements): void {
        $statements[] = $query;
    });
    $connection->beginTransaction();

    try {
        $search();
        $last = end($statements);
        $plan = implode("\n", array_column($connection->select('EXPLAIN '.$last->sql, $last->bindings), 'QUERY PLAN'));

        return ['plan' => $plan, 'enable_seqscan' => (string) $connection->scalar("SELECT current_setting('enable_seqscan')")];
    } finally {
        $connection->rollBack();
    }
}

/**
 * @return list<object>
 */
function latencyStatements(callable $callback): array
{
    $statements = [];
    DB::connection('pgvector')->listen(function ($query) use (&$statements): void {
        $statements[] = $query;
    });
    $callback();

    return $statements;
}

it('uses the hnsw index for an unfiltered search, not for an exact one, and leaves a filtered one to the planner', function () {
    $store = latencyStore();
    latencyLargeCollection($store, $this->collection);
    $query = latencyVector(1536);
    $index = 'vcol_'.substr(hash('sha256', $this->collection), 0, 32).'_embedding_idx';

    $dense = latencyPlan(fn () => $store->collection($this->collection)->usingVector($query)->take(10)->get());
    expect($dense['plan'])->toContain("Index Scan using {$index}")
        ->and($dense['enable_seqscan'])->toBe('off');

    $exact = latencyPlan(fn () => $store->collection($this->collection)->usingVector($query)->options(['exact' => true])->take(10)->get());
    expect($exact['plan'])->not->toContain($index)
        ->toContain('Seq Scan')
        ->and($exact['enable_seqscan'])->toBe('on');

    $filtered = latencyPlan(fn () => $store->collection($this->collection)->usingVector($query)->where('group', 1)->take(10)->get());
    expect($filtered['enable_seqscan'])->toBe('on');
});

it('uses the hnsw index for the dense list of an unfiltered hybrid search and leaves a filtered one to the planner', function () {
    $store = latencyStore();
    latencyLargeCollection($store, $this->collection, keyword: true);
    $query = latencyVector(1536);
    $index = 'vcol_'.substr(hash('sha256', $this->collection), 0, 32).'_embedding_idx';
    $hybrid = fn () => $store->collection($this->collection)->usingVector($query)->keywords('abc')->hybrid(match: 'any')->take(10);

    $unfiltered = latencyPlan(fn () => $hybrid()->get());
    expect($unfiltered['plan'])->toContain("Index Scan using {$index}")
        ->and($unfiltered['enable_seqscan'])->toBe('off');

    $filtered = latencyPlan(fn () => $hybrid()->where('group', 1)->get());
    expect($filtered['plan'])->not->toContain($index)
        ->and($filtered['enable_seqscan'])->toBe('on');
});

it('keeps the planner choice with prefer_index off', function () {
    $store = latencyStore(['prefer_index' => false]);
    latencyLargeCollection($store, $this->collection);
    $query = latencyVector(1536);

    $dense = latencyPlan(fn () => $store->collection($this->collection)->usingVector($query)->take(10)->get());

    expect($dense['plan'])->toContain('Seq Scan')
        ->not->toContain('_embedding_idx')
        ->and($dense['enable_seqscan'])->toBe('on');
});

it('refuses an exact option that is not a boolean', function () {
    $store = latencyStore();
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));

    $store->collection($this->collection)->usingVector([1.0, 0.0, 0.0])->options(['exact' => 'yes'])->get();
})->throws(VectorQueryException::class, 'exact must be true or false');

it('binds the query vector once without minScore and twice with it, in nine significant digits', function () {
    $store = latencyStore();
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));
    $store->upsert($this->collection, [new Document('a', 'a', [], [0.1, 0.2, 0.3])]);
    $vector = [0.123456789123, -1.0e-7, 2.0];
    $vectors = fn (object $statement): array => array_values(array_filter($statement->bindings, fn ($binding) => is_string($binding) && str_starts_with($binding, '[')));

    $plain = latencyStatements(fn () => $store->collection($this->collection)->usingVector($vector)->get());
    $withMinScore = latencyStatements(fn () => $store->collection($this->collection)->usingVector($vector)->minScore(0.1)->get());

    expect($vectors(end($plain)))->toBe(['[0.123456789,-1.0e-7,2]'])
        ->and(substr_count(end($plain)->sql, '::vector'))->toBe(1)
        ->and($vectors(end($withMinScore)))->toHaveCount(2);
});

it('caches the registry lookup of a search and clears it on a change through the same store', function () {
    $store = latencyStore();
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));
    $store->createCollection(new CollectionDefinition("{$this->collection}_b", dimensions: 3, metric: 'cosine'));
    $store->upsert($this->collection, [new Document('in-a', 'a', [], [1.0, 0.0, 0.0])]);
    $store->upsert("{$this->collection}_b", [new Document('in-b', 'b', [], [1.0, 0.0, 0.0])]);
    $alias = "{$this->collection}_alias";
    $store->pointAlias($alias, $this->collection);
    $lookups = fn (array $statements): int => count(array_filter($statements, fn ($statement) => str_contains($statement->sql, 'vector_collection_registry')));
    $search = fn () => array_map(fn ($hit) => $hit->id, $store->collection($alias)->usingVector([1.0, 0.0, 0.0])->get()->hits);

    $first = latencyStatements($search);
    $second = latencyStatements($search);
    expect($lookups($first))->toBeGreaterThan(0)
        ->and($lookups($second))->toBe(0);

    $store->pointAlias($alias, "{$this->collection}_b");
    expect($search())->toBe(['in-b']);

    $store->pointAlias($alias, $this->collection);
    expect($search())->toBe(['in-a']);
    $store->deleteCollection("{$this->collection}_b");
    $store->createCollection(new CollectionDefinition("{$this->collection}_b", dimensions: 3, metric: 'cosine'));
    $store->pointAlias($alias, "{$this->collection}_b");
    expect($search())->toBe([]);
});

it('searches again uncached when another store dropped the cached table', function () {
    $store = latencyStore();
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));
    $store->upsert($this->collection, [new Document('old', 'old', [], [1.0, 0.0, 0.0])]);
    $search = fn () => array_map(fn ($hit) => $hit->id, $store->collection($this->collection)->usingVector([1.0, 0.0, 0.0])->get()->hits);
    expect($search())->toBe(['old']);

    // A second store instance: its changes do not clear the first one's cache.
    latencyStore()->deleteCollection($this->collection);

    $search();
})->throws(VectorCollectionNotFoundException::class);

it('writes to the new target after another store switched the alias the cache resolved', function () {
    $store = latencyStore();
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, metric: 'cosine'));
    $store->createCollection(new CollectionDefinition("{$this->collection}_b", dimensions: 3, metric: 'cosine'));
    $alias = "{$this->collection}_alias";
    $store->pointAlias($alias, $this->collection);
    $store->collection($alias)->usingVector([1.0, 0.0, 0.0])->get();

    // A second store instance: its alias switch does not clear the first one's search cache.
    latencyStore()->pointAlias($alias, "{$this->collection}_b");
    $store->upsert($alias, [new Document('new', 'new', [], [1.0, 0.0, 0.0])]);

    expect($store->countDocuments($this->collection))->toBe(0)
        ->and($store->countDocuments("{$this->collection}_b"))->toBe(1);
});
