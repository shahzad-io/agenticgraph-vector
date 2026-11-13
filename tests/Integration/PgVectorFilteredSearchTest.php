<?php

use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Support\Facades\DB;

// Spec section 13 (F1 to F9): declared fields get plain predicates and field indexes; a filtered dense search counts
// its matches up to T + 1 and takes the exact path at most T, the vector index above.

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    $this->collection = 'filtered_'.uniqid();
    $this->storage = 'vcol_'.substr(hash('sha256', $this->collection), 0, 32);
});

afterEach(function () {
    try {
        $store = Vector::store('pgvector');

        if ($store->collectionExists($this->collection)) {
            $store->deleteCollection($this->collection);
        }
    } catch (Throwable) {
        // Best-effort cleanup between tests.
    }
});

/**
 * T is max(filter_exact_max_rows, share x rows): 100 here, so a filter on one tenant (60 rows) is exact and one on
 * every tenant but one is not.
 */
function filteredStore(array $config = [])
{
    config()->set('vector.stores.pgvector', array_merge(config('vector.stores.pgvector'), ['filter_exact_max_rows' => 100, 'filter_exact_min_share' => 0.0], $config));
    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);

    return Vector::store('pgvector');
}

/**
 * 6,000 rows of 32 dimensions; each field keeps 1% of the rows for its selective value: tenant t0 (one of 100),
 * rank 5, price >= 59.5, active true, at in the last hour, tags containing 'rare'.
 */
function filteredCollection($store, string $name, string $storage, bool $keyword = false): void
{
    $store->createCollection(new CollectionDefinition($name, dimensions: 32, metric: 'cosine', keyword: $keyword, filterable: [
        'tenant' => 'keyword', 'rank' => 'integer', 'price' => 'float', 'active' => 'bool', 'at' => 'datetime', 'tags' => 'keyword_list',
    ]));
    $connection = DB::connection('pgvector');
    $connection->select('SELECT setseed(0.31)');
    $connection->statement("INSERT INTO {$storage} (id, content, metadata, embedding)
        SELECT 'd' || i, 'c', jsonb_build_object(
            'tenant', 't' || (i % 100), 'rank', i % 100, 'price', (i / 100.0)::float8, 'active', i % 100 = 7,
            'at', to_char(timestamp '2026-01-01' + i * interval '1 minute', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'),
            'tags', CASE WHEN i % 100 = 3 THEN '[\"rare\", \"x\"]'::jsonb ELSE '[\"x\"]'::jsonb END),
            (SELECT array_agg(random() - 0.5) FROM generate_series(1, 32) g WHERE g > -i)::vector
        FROM generate_series(1, 6000) i");
    $connection->statement("ANALYZE {$storage}");
}

function fieldIndex(string $storage, string $field): string
{
    return "{$storage}_f".substr(hash('sha256', $field), 0, 10);
}

/**
 * Runs the search in an outer transaction, so its SET LOCAL settings are still on, and explains its statement.
 *
 * @return array{plan: string, sql: string, statements: list<object>, settings: array<string, string>}
 */
function filteredPlan(callable $search): array
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
        $settings = [];

        foreach (['enable_indexscan', 'enable_seqscan', 'enable_bitmapscan', 'enable_sort', 'jit', 'hnsw.iterative_scan', 'hnsw.ef_search'] as $name) {
            $settings[$name] = (string) $connection->scalar('SELECT current_setting(?)', [$name]);
        }

        return ['plan' => $plan, 'sql' => $last->sql, 'statements' => $statements, 'settings' => $settings];
    } finally {
        $connection->rollBack();
    }
}

/**
 * @param  list<object>  $statements
 * @return list<object>
 */
function countStatements(array $statements): array
{
    return array_values(array_filter($statements, static fn (object $query): bool => str_starts_with($query->sql, 'SELECT count(*) FROM (SELECT 1 FROM')));
}

function filteredQuery(): array
{
    return array_map(static fn (int $i): float => sin($i), range(1, 32));
}

it('creates one field index per declared field and serves each type from it', function () {
    $store = filteredStore();
    filteredCollection($store, $this->collection, $this->storage);
    $query = filteredQuery();
    $search = fn () => $store->collection($this->collection)->usingVector($query)->take(10);

    foreach ([
        'tenant' => fn () => $search()->where('tenant', 't0')->get(),
        'rank' => fn () => $search()->where('rank', '>=', 99)->get(),
        'price' => fn () => $search()->where('price', '>=', 59.5)->get(),
        'active' => fn () => $search()->where('active', true)->get(),
        'at' => fn () => $search()->where('at', '>=', '2026-01-05T03:00:00Z')->get(),
        'tags' => fn () => $search()->whereContains('tags', 'rare')->get(),
    ] as $field => $run) {
        $explained = filteredPlan($run);
        expect($explained['plan'])->toContain(fieldIndex($this->storage, $field), $field)
            ->not->toContain("{$this->storage}_embedding_idx")
            ->and($explained['settings']['enable_indexscan'])->toBe('off', $field);
    }

    $indexes = DB::connection('pgvector')->select('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$this->storage]);
    expect($indexes)->toHaveCount(8); // primary key, embedding, six fields
});

it('takes the exact path for a small count and the vector index for a large one, with hits in score order', function () {
    $store = filteredStore();
    filteredCollection($store, $this->collection, $this->storage);
    $query = filteredQuery();

    $small = filteredPlan(fn () => $store->collection($this->collection)->usingVector($query)->where('tenant', 't0')->take(10)->get());
    expect($small['plan'])->toContain(fieldIndex($this->storage, 'tenant'))->not->toContain('embedding_idx')
        ->and($small['settings']['enable_indexscan'])->toBe('off');

    $large = filteredPlan(fn () => $store->collection($this->collection)->usingVector($query)->whereNotIn('tenant', ['t0'])->take(10)->get());
    expect($large['plan'])->toContain("Index Scan using {$this->storage}_embedding_idx")
        ->and($large['settings'])->toMatchArray([
            'enable_seqscan' => 'off', 'enable_bitmapscan' => 'off', 'enable_sort' => 'off', 'jit' => 'off',
            'hnsw.iterative_scan' => 'relaxed_order', 'hnsw.ef_search' => '160',
        ]);

    $own = filteredPlan(fn () => $store->collection($this->collection)->usingVector($query)->whereNotIn('tenant', ['t0'])->options(['hnsw.ef_search' => 77])->take(10)->get());
    expect($own['settings']['hnsw.ef_search'])->toBe('77');

    $hits = $store->collection($this->collection)->usingVector($query)->where('rank', '>=', 10)->take(50)->get();
    $scores = array_map(static fn ($hit): float => $hit->score, $hits->hits);
    $sorted = $scores;
    rsort($sorted);
    expect($hits->hits)->toHaveCount(50)->and($scores)->toBe($sorted);
});

it('counts the matches only up to T + 1, with T from the row count of the table statistics', function () {
    $store = filteredStore(['filter_exact_max_rows' => 10, 'filter_exact_min_share' => 0.02]);
    filteredCollection($store, $this->collection, $this->storage);

    $explained = filteredPlan(fn () => $store->collection($this->collection)->usingVector(filteredQuery())->where('rank', '>=', 10)->take(10)->get());
    $counts = countStatements($explained['statements']);
    expect($counts)->toHaveCount(1)
        ->and(end($counts[0]->bindings))->toBe(121) // T = max(10, 0.02 x 6,000)
        ->and((int) DB::connection('pgvector')->scalar($counts[0]->sql, $counts[0]->bindings))->toBe(121);
});

it('follows filter_plan in each of its four values', function () {
    $store = filteredStore();
    filteredCollection($store, $this->collection, $this->storage);
    $query = filteredQuery();
    $plan = fn (string $setting, string $tenant) => filteredPlan(fn () => filteredStore(['filter_plan' => $setting])
        ->collection($this->collection)->usingVector($query)->where('tenant', $tenant === 'one' ? '=' : '!=', 't0')->take(10)->get());

    $auto = $plan('auto', 'one');
    expect(countStatements($auto['statements']))->toHaveCount(1)
        ->and($auto['settings']['enable_indexscan'])->toBe('off');

    $exact = $plan('exact', 'all but one');
    expect(countStatements($exact['statements']))->toBe([])
        ->and($exact['settings']['enable_indexscan'])->toBe('off')
        ->and($exact['plan'])->not->toContain('embedding_idx');

    $index = $plan('index', 'one');
    expect(countStatements($index['statements']))->toBe([])
        ->and($index['plan'])->toContain("Index Scan using {$this->storage}_embedding_idx");

    $planner = $plan('planner', 'one');
    expect(countStatements($planner['statements']))->toBe([])
        ->and($planner['settings'])->toMatchArray(['enable_indexscan' => 'on', 'enable_seqscan' => 'on', 'enable_bitmapscan' => 'on']);

    expect(fn () => filteredStore(['filter_plan' => 'fast'])->collection($this->collection)->usingVector($query)->where('tenant', 't0')->get())
        ->toThrow(VectorQueryException::class, 'filter_plan must be one of');
});

it('leaves a filter on an undeclared field and a filtered hybrid search to the planner, with plain predicates where declared', function () {
    $store = filteredStore();
    filteredCollection($store, $this->collection, $this->storage, keyword: true);

    $undeclared = filteredPlan(fn () => $store->collection($this->collection)->usingVector(filteredQuery())->where('tenant', 't0')->where('other', 1)->take(10)->get());
    expect(countStatements($undeclared['statements']))->toBe([])
        ->and($undeclared['settings']['enable_indexscan'])->toBe('on')
        ->and($undeclared['sql'])->toContain("(metadata->>'tenant') = ?")
        ->toContain("jsonb_typeof(metadata->'other')");

    $hybrid = filteredPlan(fn () => $store->collection($this->collection)->usingVector(filteredQuery())->keywords('c')->hybrid()->where('tenant', 't0')->take(10)->get());
    expect(countStatements($hybrid['statements']))->toBe([])
        ->and($hybrid['settings']['enable_indexscan'])->toBe('on')
        ->and($hybrid['plan'])->toContain(fieldIndex($this->storage, 'tenant'));
});

it('uses the plain predicates in deleteByFilter and listIds', function () {
    $store = filteredStore();
    filteredCollection($store, $this->collection, $this->storage);
    $connection = DB::connection('pgvector');
    $statements = [];
    $connection->listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $page = $store->collection($this->collection)->where('rank', 5)->listIds(limit: 1000);
    $store->collection($this->collection)->where('tenant', 't0')->delete();

    expect($page->ids)->toHaveCount(60)
        ->and(implode("\n", $statements))->toContain("((metadata->>'rank')::numeric) = ?")
        ->toContain("DELETE FROM {$this->storage} WHERE (metadata->>'tenant') = ?")
        ->not->toContain('jsonb_typeof')
        ->and($store->countDocuments($this->collection))->toBe(5940);
});
