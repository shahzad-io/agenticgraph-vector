<?php

use AgenticGraph\Vector\Events\VectorMigrationProgressed;
use AgenticGraph\Vector\Events\VectorRerankCompleted;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    $this->registry = 'vector_registry_b6_'.uniqid();
    config()->set('vector.stores.pgvector.registry_table', $this->registry);
    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);
    $this->collection = 'hybrid_'.uniqid();
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
 * @return list<string>
 */
function keywordMatchIds(string $collection, string $keywords, ?string $match = null): array
{
    $hits = Vector::store('pgvector')->collection($collection)->usingVector([1.0, 0.0, 0.0])->keywords($keywords)->hybrid(match: $match)->take(50)->get()->hits;
    $ids = array_values(array_map(fn (SearchHit $hit): string => (string) $hit->id, array_filter($hits, fn (SearchHit $hit): bool => $hit->scores['keyword'] !== null)));
    sort($ids);

    return $ids;
}

function storageOf(string $registry, string $collection): string
{
    return (string) DB::connection('pgvector')->table($registry)->where('name', $collection)->value('storage_identifier');
}

it('stems each document in the configuration its language maps to, and simple for any other', function () {
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, keyword: true));
    $store->collection($this->collection)->upsert([
        Document::make('en', 'The runners were running quickly', ['lang' => 'en'], [1.0, 0.0, 0.0]),
        Document::make('english', 'Print Certification', ['lang' => 'english'], [1.0, 0.0, 0.0]),
        Document::make('es', 'Información sobre la educación continua', ['lang' => 'es'], [1.0, 0.0, 0.0]),
        Document::make('none', 'running shoes', [], [1.0, 0.0, 0.0]),
        Document::make('fr', 'running club', ['lang' => 'fr'], [1.0, 0.0, 0.0]),
    ]);

    expect(keywordMatchIds($this->collection, 'run'))->toBe(['en'])
        ->and(keywordMatchIds($this->collection, 'running'))->toBe(['en', 'fr', 'none'])
        ->and(keywordMatchIds($this->collection, 'certifications printed'))->toBe(['english'])
        ->and(keywordMatchIds($this->collection, 'educación continua'))->toBe(['es'])
        ->and(keywordMatchIds($this->collection, 'informaciones'))->toBe(['es'])
        // any (the default): any stemmed word matches, in each document's own configuration; English drops stop words.
        ->and(keywordMatchIds($this->collection, 'the running app', 'websearch'))->toBe([])
        ->and(keywordMatchIds($this->collection, 'the running app'))->toBe(['en', 'fr', 'none'])
        ->and(keywordMatchIds($this->collection, 'the', 'any'))->toBe([]);

    // The column is generated: a metadata change re-stems the document without re-embedding.
    $store->collection($this->collection)->updateMetadata(['none' => ['lang' => 'en']]);

    expect(keywordMatchIds($this->collection, 'run'))->toBe(['en', 'none']);
});

it('uses a custom language map and field, and refuses an unknown configuration', function () {
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3, keyword: true, languages: ['de' => 'german'], languageField: 'locale'));
    $store->collection($this->collection)->upsert([
        Document::make('de', 'Häuser und Hunde', ['locale' => 'de'], [1.0, 0.0, 0.0]),
        Document::make('en', 'running', ['lang' => 'en'], [1.0, 0.0, 0.0]),
    ]);

    expect(keywordMatchIds($this->collection, 'Haus'))->toBe(['de'])
        ->and(keywordMatchIds($this->collection, 'run'))->toBe([])
        ->and($store->describeCollection($this->collection)['keyword'])->toBe(['languages' => ['de' => 'german'], 'language_field' => 'locale']);

    $unknown = $this->collection.'_unknown';

    expect(fn () => $store->createCollection(new CollectionDefinition($unknown, dimensions: 3, keyword: true, languages: ['xx' => 'klingon'])))
        ->toThrow(VectorQueryException::class, 'Unknown Postgres text search configuration(s) in the language map: klingon.')
        ->and($store->collectionExists($unknown))->toBeFalse();
});

it('keeps scanning HNSW with a selective filter until it has take() hits, unless iterative scan is off', function () {
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 16));
    $vector = fn (int $i): array => array_map(fn (int $j): float => sin($i * 7.1 + $j * 1.3), range(0, 15));
    $store->collection($this->collection)->upsert(array_map(
        fn (int $i): Document => Document::make("d{$i}", "document {$i}", ['brand' => 'b'.($i % 50)], $vector($i)),
        range(1, 2000),
    ));

    // Force the HNSW index (a table this small would otherwise be scanned exactly).
    $search = fn (array $options) => DB::connection('pgvector')->transaction(function () use ($vector, $options) {
        DB::connection('pgvector')->statement('SET LOCAL enable_seqscan = off');

        return Vector::store('pgvector')->collection($this->collection)->where('brand', 'b3')->usingVector($vector(7))->options($options)->take(10)->get();
    });

    $off = $search(['hnsw.iterative_scan' => 'off']);
    $default = $search([]);
    $scores = $default->pluck('score');
    $sorted = $scores;
    rsort($sorted);

    expect($off->count())->toBeLessThan(10)
        ->and($default->count())->toBe(10)
        ->and($scores)->toBe($sorted)
        ->and(array_unique($default->pluck('brand')))->toBe(['b3'])
        ->and($search(['hnsw.iterative_scan' => 'strict_order'])->count())->toBe(10)
        ->and(fn () => $search(['hnsw.iterative_scan' => 'sideways']))->toThrow(VectorQueryException::class, 'hnsw.iterative_scan must be one of');

    config()->set('vector.stores.pgvector.iterative_scan', 'off');
    app()->forgetInstance(VectorManager::class);
    Vector::clearResolvedInstance(VectorManager::class);

    expect($search([])->count())->toBeLessThan(10);
});

it('builds the keyword index after the copy when a migration adds keyword search', function () {
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 3));
    $store->collection($this->collection)->upsert(array_map(
        fn (int $i): Document => Document::make("d{$i}", "document {$i}", [], [1.0, (float) $i, 0.0]),
        range(1, 5),
    ));
    $during = [];

    Event::listen(VectorMigrationProgressed::class, function (VectorMigrationProgressed $event) use (&$during): void {
        if ($event->phase === 'copying' && $event->copied > 0) {
            $during[] = DB::connection('pgvector')->scalar('SELECT to_regclass(?) IS NOT NULL', [storageOf($this->registry, $event->target).'_keyword_idx']);
        }
    });

    Vector::migrate($this->collection, new CollectionDefinition($this->collection, keyword: true), store: 'pgvector', mode: 'copy', batchSize: 2);
    $target = storageOf($this->registry, "{$this->collection}__v2");

    expect($during)->not->toBeEmpty()->not->toContain(true)
        ->and(DB::connection('pgvector')->scalar('SELECT pg_get_indexdef(to_regclass(?))', ["{$target}_keyword_idx"]))->toContain('USING gin (keywords)')
        ->and(keywordMatchIds($this->collection, '"document 4"', 'websearch'))->toBe(['d4']);
});

it('raises hnsw.ef_search so an unfiltered HNSW search returns more than 40 hits, unless the caller sets it', function () {
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 16, keyword: true));
    $vector = fn (int $i): array => array_map(fn (int $j): float => sin($i * 7.1 + $j * 1.3), range(0, 15));
    $store->collection($this->collection)->upsert(array_map(
        fn (int $i): Document => Document::make("d{$i}", "document {$i}", [], $vector($i)),
        range(1, 300),
    ));

    // Force the HNSW index (a table this small would otherwise be scanned exactly).
    $search = fn (Closure $build) => DB::connection('pgvector')->transaction(function () use ($build) {
        DB::connection('pgvector')->statement('SET LOCAL enable_seqscan = off');

        return $build(Vector::store('pgvector')->collection($this->collection))->get();
    });
    $dense = fn ($builder) => $builder->usingVector($vector(7));

    Event::fake([VectorRerankCompleted::class]);

    expect($search(fn ($b) => $dense($b)->take(100))->count())->toBe(100)
        ->and($search(fn ($b) => $dense($b)->rerank(using: 'null', candidates: 80, query: 'q')->take(5))->count())->toBe(5)
        ->and(Event::dispatched(VectorRerankCompleted::class)[0][0]->documentCount)->toBe(80)
        ->and($search(fn ($b) => $dense($b)->options(['hnsw.ef_search' => 20])->take(100))->count())->toBe(20)
        // Hybrid: the vector list holds depth (80) candidates; no keyword matches, so the fused list is that list.
        ->and($search(fn ($b) => $dense($b)->keywords('zzzz')->hybrid(depth: 80)->take(80))->count())->toBe(80);
});

it('guard: never lowers a higher hnsw.ef_search setting', function () {
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($this->collection, dimensions: 16));
    $vector = fn (int $i): array => array_map(fn (int $j): float => sin($i * 7.1 + $j * 1.3), range(0, 15));
    $store->collection($this->collection)->upsert(array_map(fn (int $i): Document => Document::make("d{$i}", "document {$i}", [], $vector($i)), range(1, 300)));

    $count = DB::connection('pgvector')->transaction(function () use ($vector) {
        DB::connection('pgvector')->statement('SET LOCAL enable_seqscan = off');
        DB::connection('pgvector')->statement('SET LOCAL hnsw.ef_search = 200');

        $count = Vector::store('pgvector')->collection($this->collection)->usingVector($vector(7))->take(150)->get()->count();

        return [$count, DB::connection('pgvector')->scalar('SHOW hnsw.ef_search')];
    });

    expect($count)->toBe([150, '200']);
});
