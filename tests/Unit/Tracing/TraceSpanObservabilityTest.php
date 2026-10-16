<?php

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Events\VectorOperationFailed;
use AgenticGraph\Vector\Exceptions\VectorEmbeddingException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Providers\DeterministicEmbeddingProvider;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\Tracing\VectorSpanSchema;
use AgenticGraph\Vector\Tracing\VectorTraceContext;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Tracing\Tracer;

test('contract freeze guard: vector span schema v2 attribute keys are frozen', function () {
    expect(VectorSpanSchema::VERSION)->toBe('v2')
        ->and(VectorSpanSchema::FROZEN_ATTRIBUTE_KEYS)->toBe([
            'vector.store',
            'vector.collection',
            'vector.operation',
            'vector.top_k',
            'vector.result_count',
            'vector.latency_ms',
            'vector.score_min',
            'vector.score_max',
            'vector.filter_count',
            'vector.embedding_ms',
        ]);
});

test('contract freeze guard: built search attributes only use frozen keys', function () {
    $query = new SearchQuery(
        collection: 'articles',
        vector: [1.0, 0.0, 0.0],
        topK: 5,
    );

    $attributes = VectorSpanSchema::baseAttributes('search', VectorTraceContext::forSearch($query));

    expect(array_keys($attributes))->toEqualCanonicalizing([
        'vector.store',
        'vector.collection',
        'vector.operation',
        'vector.top_k',
    ]);

    VectorSpanSchema::enrichFromResult(
        $attributes,
        'search',
        new SearchResult([
            new SearchHit('a', 0.9, 'alpha'),
            new SearchHit('b', 0.7, 'beta'),
        ], 2),
        12,
    );

    expect(array_keys($attributes))->toEqualCanonicalizing([
        'vector.store',
        'vector.collection',
        'vector.operation',
        'vector.top_k',
        'vector.latency_ms',
        'vector.result_count',
        'vector.score_min',
        'vector.score_max',
    ]);
});

it('dispatches VectorOperationCompleted on successful search through the builder', function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    Event::fake([VectorOperationCompleted::class, VectorOperationFailed::class]);

    $collection = 'obs_'.uniqid();
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($collection, dimensions: 8));

    Vector::store('pgvector')
        ->collection($collection)
        ->upsert([
            Document::make('doc', 'observability search probe'),
        ]);

    Vector::store('pgvector')
        ->collection($collection)
        ->similarTo('observability search probe')
        ->take(1)
        ->get();

    Event::assertDispatched(VectorOperationCompleted::class, function (VectorOperationCompleted $event) use ($collection): bool {
        return $event->operation === 'search'
            && $event->store === 'pgvector'
            && $event->collection === $collection
            && $event->resultCount === 1
            && $event->durationMs >= 0;
    });

    Event::assertNotDispatched(VectorOperationFailed::class);

    $store->deleteCollection($collection);
});

it('dispatches VectorOperationFailed and rethrows when search fails', function () {
    Event::fake([VectorOperationCompleted::class, VectorOperationFailed::class]);

    $query = new SearchQuery(collection: 'missing', vector: [0.1, 0.2, 0.3]);

    expect(fn () => TraceSpan::search(
        $query,
        fn (): SearchResult => throw new VectorQueryException('search failed'),
        VectorTraceContext::forSearch($query),
    ))->toThrow(VectorQueryException::class, 'search failed');

    Event::assertDispatched(VectorOperationFailed::class, function (VectorOperationFailed $event): bool {
        return $event->operation === 'search'
            && $event->exception instanceof VectorQueryException;
    });

    Event::assertNotDispatched(VectorOperationCompleted::class);
});

it('does not dispatch events when vector events are disabled', function () {
    config()->set('vector.events.enabled', false);

    Event::fake([VectorOperationCompleted::class]);

    TraceSpan::search(
        new SearchQuery('articles', [0.1]),
        fn (): SearchResult => SearchResult::empty(),
        VectorTraceContext::forCollection('articles'),
    );

    Event::assertNothingDispatched();
});

it('passes through TraceSpan when agenticgraph is absent', function () {
    expect(TraceSpan::isAgenticGraphTracingAvailable())->toBeFalse();

    $executed = false;

    $result = TraceSpan::search(
        new SearchQuery('articles', [0.1]),
        function () use (&$executed): SearchResult {
            $executed = true;

            return SearchResult::empty();
        },
        VectorTraceContext::forCollection('articles'),
    );

    expect($executed)->toBeTrue()
        ->and($result)->toBeInstanceOf(SearchResult::class);
});

it('passes enriched attributes by reference to Tracer::span after callback', function () {
    app()->singleton(Tracer::class);

    expect(TraceSpan::isAgenticGraphTracingAvailable())->toBeTrue();

    /** @var Tracer $tracer */
    $tracer = app()->make(Tracer::class);

    $query = new SearchQuery(collection: 'articles', vector: [0.1, 0.2], topK: 2);

    TraceSpan::search(
        $query,
        fn (): SearchResult => new SearchResult([
            new SearchHit('a', 0.91, 'alpha'),
            new SearchHit('b', 0.72, 'beta'),
        ], 2),
        VectorTraceContext::forSearch($query),
    );

    expect($tracer->exportedAttributes)->not->toBeNull()
        ->and($tracer->exportedAttributes)->toHaveKeys([
            'vector.result_count',
            'vector.latency_ms',
            'vector.score_min',
            'vector.score_max',
        ])
        ->and($tracer->exportedAttributes['vector.result_count'])->toBe(2)
        ->and($tracer->exportedAttributes['vector.score_min'])->toBe(0.72)
        ->and($tracer->exportedAttributes['vector.score_max'])->toBe(0.91);
});

it('documents that span attributes must be passed by reference to survive export', function () {
    $attributes = ['vector.operation' => 'search'];

    $spanByValue = function (string $name, callable $callback, array $attrs): array {
        $result = $callback();

        return [$result, $attrs];
    };

    [$result, $exported] = $spanByValue(
        'vector.search',
        function () use (&$attributes): string {
            $attributes['vector.result_count'] = 2;

            return 'ok';
        },
        $attributes,
    );

    expect($result)->toBe('ok')
        ->and($exported)->not->toHaveKey('vector.result_count')
        ->and($attributes)->toHaveKey('vector.result_count');
});

it('dispatches one upsert event for a large batched upsert', function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    Event::fake([VectorOperationCompleted::class]);

    $collection = 'obs_batch_'.uniqid();
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($collection, dimensions: 8));

    $documents = [];
    for ($index = 0; $index < 250; $index++) {
        $documents[] = Document::make("doc-{$index}", "content {$index}");
    }

    Vector::store('pgvector')
        ->collection($collection)
        ->upsert($documents);

    $upserts = Event::dispatched(VectorOperationCompleted::class, fn (VectorOperationCompleted $event): bool => $event->operation === 'upsert');

    expect($upserts)->toHaveCount(1)
        ->and($upserts->first()[0]->collection)->toBe($collection)
        ->and($upserts->first()[0]->documentCount)->toBe(250);

    $store->deleteCollection($collection);
});

function memoryTracingStore(string $name = 'memory'): VectorStoreContract
{
    config()->set("vector.stores.{$name}", ['driver' => 'memory']);

    $store = Vector::store($name);
    $store->createCollection(new CollectionDefinition('articles', dimensions: 8));
    $store->collection('articles')->upsert([Document::make('doc', 'observability search probe')]);

    return $store;
}

function useEmbeddingProvider(EmbeddingProviderContract $provider): void
{
    Embeddings::extend('observability', static fn (): EmbeddingProviderContract => $provider);
    config()->set('vector.embeddings.default', 'observability');
}

it('reports the name of the store actually used on spans and events', function () {
    app()->singleton(Tracer::class);
    Event::fake([VectorOperationCompleted::class]);

    config()->set('vector.default', 'pgvector');
    memoryTracingStore('secondary')->collection('articles')->usingVector(array_fill(0, 8, 0.5))->get();

    $stores = Event::dispatched(VectorOperationCompleted::class)->map(fn (array $args): string => $args[0]->store)->unique()->values()->all();
    $spanStores = array_values(array_unique(array_map(fn (array $span): string => $span[1]['vector.store'], app(Tracer::class)->spans)));

    expect($stores)->toBe(['secondary'])
        ->and($spanStores)->toBe(['secondary']);
});

it('puts the query embedding time on the search span and event', function () {
    app()->singleton(Tracer::class);
    $store = memoryTracingStore();
    useEmbeddingProvider(new class(new DeterministicEmbeddingProvider(8)) implements EmbeddingProviderContract
    {
        public function __construct(private readonly EmbeddingProviderContract $inner) {}

        public function embed(string $text): array
        {
            usleep(30_000);

            return $this->inner->embed($text);
        }

        public function embedBatch(array $texts): array
        {
            return $this->inner->embedBatch($texts);
        }

        public function dimensions(): int
        {
            return 8;
        }

        public function model(): string
        {
            return 'slow';
        }
    });
    Event::fake([VectorOperationCompleted::class]);

    $store->collection('articles')->similarTo('observability search probe')->take(1)->get();

    [$name, $attributes] = app(Tracer::class)->spans[array_key_last(app(Tracer::class)->spans)];
    $event = Event::dispatched(VectorOperationCompleted::class)->last()[0];

    expect($name)->toBe('vector.search')
        ->and($attributes['vector.embedding_ms'])->toBeGreaterThanOrEqual(25)
        ->and($attributes['vector.latency_ms'])->toBeGreaterThanOrEqual($attributes['vector.embedding_ms'])
        ->and($event->operation)->toBe('search')
        ->and($event->embeddingMs)->toBeGreaterThanOrEqual(25)
        ->and($event->durationMs)->toBeGreaterThanOrEqual($event->embeddingMs);

    $store->collection('articles')->usingVector(array_fill(0, 8, 0.5))->get();

    expect(Event::dispatched(VectorOperationCompleted::class)->last()[0]->embeddingMs)->toBeNull()
        ->and(app(Tracer::class)->spans[array_key_last(app(Tracer::class)->spans)][1])->not->toHaveKey('vector.embedding_ms');
});

it('puts the ingest embedding time on the upsert span and event, for both stores', function (string $store) {
    if ($store === 'pgvector' && ! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    app()->singleton(Tracer::class);
    config()->set('vector.stores.memory', ['driver' => 'memory']);
    useEmbeddingProvider(new class(new DeterministicEmbeddingProvider(8)) implements EmbeddingProviderContract
    {
        public function __construct(private readonly EmbeddingProviderContract $inner) {}

        public function embed(string $text): array
        {
            return $this->inner->embed($text);
        }

        public function embedBatch(array $texts): array
        {
            usleep(30_000);

            return $this->inner->embedBatch($texts);
        }

        public function dimensions(): int
        {
            return 8;
        }

        public function model(): string
        {
            return 'slow-batch';
        }
    });
    $collection = 'obs_ingest_'.uniqid();
    Vector::store($store)->createCollection(new CollectionDefinition($collection, dimensions: 8));
    Event::fake([VectorOperationCompleted::class]);

    Vector::store($store)->collection($collection)->upsert([Document::make('a', 'alpha'), Document::make('b', 'beta')]);

    [$name, $attributes] = app(Tracer::class)->spans[array_key_last(app(Tracer::class)->spans)];
    $event = Event::dispatched(VectorOperationCompleted::class, fn (VectorOperationCompleted $event): bool => $event->operation === 'upsert')->last()[0];

    expect($name)->toBe('vector.upsert')
        ->and($attributes['vector.embedding_ms'])->toBeGreaterThanOrEqual(25)
        ->and($event->embeddingMs)->toBeGreaterThanOrEqual(25)
        ->and($event->durationMs)->toBeGreaterThanOrEqual($event->embeddingMs);

    Vector::store($store)->deleteCollection($collection);
})->with(['memory', 'pgvector']);

it('fires VectorOperationFailed with the store name when the query embedding fails', function () {
    $store = memoryTracingStore();
    useEmbeddingProvider(new class implements EmbeddingProviderContract
    {
        public function embed(string $text): array
        {
            throw new VectorEmbeddingException('Embedding generation failed: provider down');
        }

        public function embedBatch(array $texts): array
        {
            return [];
        }

        public function dimensions(): int
        {
            return 8;
        }

        public function model(): string
        {
            return 'down';
        }
    });
    Event::fake([VectorOperationCompleted::class, VectorOperationFailed::class]);

    expect(fn () => $store->collection('articles')->similarTo('anything')->get())
        ->toThrow(VectorEmbeddingException::class, 'provider down');

    Event::assertDispatched(VectorOperationFailed::class, fn (VectorOperationFailed $event): bool => $event->operation === 'search'
        && $event->store === 'memory'
        && $event->collection === 'articles'
        && $event->exception instanceof VectorEmbeddingException
        && $event->embeddingMs !== null);
    Event::assertNotDispatched(VectorOperationCompleted::class);
});

it('emits exactly one event per store operation, collection management included', function () {
    Event::fake([VectorOperationCompleted::class]);
    config()->set('vector.stores.memory', ['driver' => 'memory']);

    $store = Vector::store('memory');
    $store->createCollection(new CollectionDefinition('articles', dimensions: 3));
    $store->collectionExists('articles');
    $store->listCollections();
    $builder = $store->collection('articles');
    $builder->upsert([Document::make('a', 'a', ['n' => 1], [1.0, 0.0, 0.0])]);
    $builder->insert([Document::make('b', 'b', ['n' => 2], [1.0, 0.0, 0.0])]);
    $builder->fetch(['a']);
    $builder->listIds();
    $builder->updateMetadata(['a' => ['n' => 3]]);
    $builder->usingVector([1.0, 0.0, 0.0])->get();
    $builder->delete('a');
    $builder->where('n', 2)->delete();
    $store->search(new SearchQuery('articles', [1.0, 0.0, 0.0]));
    $store->healthCheck();
    $store->deleteCollection('articles');

    $operations = Event::dispatched(VectorOperationCompleted::class)->map(fn (array $args): string => $args[0]->operation)->values()->all();

    expect($operations)->toBe([
        'create_collection', 'collection_exists', 'list_collections', 'upsert', 'insert', 'fetch', 'list_ids',
        'update_metadata', 'search', 'delete', 'delete_by_filter', 'search', 'health_check', 'delete_collection',
    ]);
});
