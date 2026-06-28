<?php

use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Events\VectorOperationFailed;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Vector;
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

test('contract freeze guard: vector span schema v1 attribute keys are frozen', function () {
    expect(VectorSpanSchema::VERSION)->toBe('v1')
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
        $this->markTestSkipped('Pgvector database is not available.');
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
        $this->markTestSkipped('Pgvector database is not available.');
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

    Event::assertDispatchedTimes(VectorOperationCompleted::class, 1);
    Event::assertDispatched(VectorOperationCompleted::class, function (VectorOperationCompleted $event) use ($collection): bool {
        return $event->operation === 'upsert'
            && $event->collection === $collection
            && $event->documentCount === 250;
    });

    $store->deleteCollection($collection);
});
