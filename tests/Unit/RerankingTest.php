<?php

use AgenticGraph\Vector\Contracts\RerankerContract;
use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Events\VectorOperationFailed;
use AgenticGraph\Vector\Events\VectorRerankCompleted;
use AgenticGraph\Vector\Events\VectorRerankFailed;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Exceptions\VectorRerankException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Rerankers\DeterministicReranker;
use AgenticGraph\Vector\Support\RerankOrder;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\RerankResult;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Tracing\Tracer;

/**
 * A reranker registered as `spy` that records what it received and reverses the order (or throws).
 *
 * @return object{calls: list<array{query: string, ids: list<string>, scores: list<array<string, mixed>|null>, topN: int}>, fail: bool}
 */
function rerankSpy(): object
{
    $spy = new class
    {
        /** @var list<array{query: string, ids: list<string>, scores: list<array<string, mixed>|null>, topN: int}> */
        public array $calls = [];

        public bool $fail = false;
    };

    Vector::rerankers()->extend('spy', fn (): RerankerContract => new class($spy) implements RerankerContract
    {
        public function __construct(private readonly object $spy) {}

        public function rerank(string $query, array $hits, int $topN): RerankResult
        {
            $this->spy->calls[] = [
                'query' => $query,
                'ids' => array_map(fn (SearchHit $hit): string => (string) $hit->id, $hits),
                'scores' => array_map(fn (SearchHit $hit): ?array => $hit->scores, $hits),
                'topN' => $topN,
            ];

            if ($this->spy->fail) {
                throw new RuntimeException('reranker down');
            }

            $ranked = array_map(fn (int $index): array => ['index' => $index, 'score' => (float) $index], array_keys($hits));

            return new RerankResult(RerankOrder::apply($hits, $ranked, $topN), 'spy-model', units: 1);
        }
    });
    config()->set('vector.rerank.providers.spy', ['driver' => 'spy', 'model' => 'spy-model']);

    return $spy;
}

/**
 * A memory collection of $count documents; document i has similarity falling with i to the query vector [1, 0, ...].
 */
function rerankCollection(int $count, bool $keyword = false): void
{
    $store = Vector::store('memory');
    $store->createCollection(new CollectionDefinition('rr', dimensions: 8, keyword: $keyword ?: null));
    $store->collection('rr')->upsert(array_map(
        fn (int $i): Document => Document::make("d{$i}", $i % 3 === 0 ? "password reset guide {$i}" : "shipping note {$i}", vector: [1.0, $i / 10, 0, 0, 0, 0, 0, 0]),
        range(0, $count - 1),
    ));
}

/** @return list<SearchHit> */
function rerankHits(int $count): array
{
    return array_map(fn (int $i): SearchHit => new SearchHit("h{$i}", 1 - $i / 100, "passage {$i}"), range(0, $count - 1));
}

// Builder

it('sends max(candidates, take) store hits to the reranker and returns take', function () {
    $spy = rerankSpy();
    rerankCollection(40);
    $builder = Vector::store('memory')->collection('rr')->usingVector([1.0, 0, 0, 0, 0, 0, 0, 0]);

    $hits = $builder->rerank(using: 'spy', candidates: 30, query: 'reset')->take(5)->get()->hits;
    $builder->rerank(using: 'spy', candidates: 3, query: 'reset')->take(5)->get();

    expect(count($spy->calls[0]['ids']))->toBe(30)
        ->and($spy->calls[0]['topN'])->toBe(5)
        ->and($spy->calls[0]['ids'])->toBe(array_map(fn (int $i): string => "d{$i}", range(0, 29)))
        ->and(array_map(fn (SearchHit $hit): string => (string) $hit->id, $hits))->toBe(['d29', 'd28', 'd27', 'd26', 'd25'])
        ->and(count($spy->calls[1]['ids']))->toBe(5);
});

it('applies minScore to the vector similarity before reranking and keeps score as the similarity', function () {
    $spy = rerankSpy();
    rerankCollection(20);
    $search = Vector::store('memory')->collection('rr')->usingVector([1.0, 0, 0, 0, 0, 0, 0, 0]);
    $plain = $search->minScore(0.9)->take(30)->get();

    $hits = $search->minScore(0.9)->rerank(using: 'spy', candidates: 30, query: 'reset')->take(30)->get()->hits;
    $similarity = array_column(array_map(fn (SearchHit $hit): array => [(string) $hit->id, $hit->score], $plain->hits), 1, 0);

    expect($plain->count())->toBeGreaterThan(0)->toBeLessThan(20)
        ->and($spy->calls[0]['ids'])->toBe(array_map(fn (SearchHit $hit): string => (string) $hit->id, $plain->hits))
        ->and(array_map(fn (SearchHit $hit): float => $hit->score, $hits))->toBe(array_map(fn (SearchHit $hit): float => $similarity[(string) $hit->id], $hits))
        ->and($hits[0]->scores)->toHaveKeys(['vector', 'rerank']);
});

it('skips the reranker when minScore leaves no candidates', function () {
    $spy = rerankSpy();
    Event::fake([VectorRerankCompleted::class]);
    rerankCollection(5);

    $result = Vector::store('memory')->collection('rr')->usingVector([0, 0, 0, 0, 0, 0, 0, 1.0])->minScore(0.99)->rerank(using: 'spy', query: 'reset')->get();

    expect($result->hits)->toBe([])
        ->and($spy->calls)->toBe([]);
    Event::assertNotDispatched(VectorRerankCompleted::class);
});

it('reranks the fused hybrid candidates in fused order', function () {
    $spy = rerankSpy();
    rerankCollection(40, keyword: true);
    $search = Vector::store('memory')->collection('rr')->usingVector([1.0, 0, 0, 0, 0, 0, 0, 0])->keywords('password reset')->hybrid(keywordWeight: 1.0);
    $fused = $search->take(30)->get()->hits;

    $search->rerank(using: 'spy', candidates: 30)->take(10)->get();

    expect($spy->calls[0]['ids'])->toBe(array_map(fn (SearchHit $hit): string => (string) $hit->id, $fused))
        ->and($spy->calls[0]['query'])->toBe('password reset')
        ->and($spy->calls[0]['scores'][0])->toHaveKeys(['vector', 'keyword', 'fused']);
});

it('reorders with the deterministic reranker and the similarTo text as the query', function () {
    rerankCollection(12);
    config()->set('vector.rerank.default', 'deterministic');

    $hits = Vector::store('memory')->collection('rr')->similarTo('password reset')->rerank(candidates: 12)->take(4)->get()->hits;

    expect(array_map(fn (SearchHit $hit): string => explode(' ', $hit->content)[0], $hits))->toBe(['password', 'password', 'password', 'password'])
        ->and($hits[0]->scores['rerank'])->toBe(2.0);
});

it('fails open: the store order cut to take, no rerank score, VectorRerankFailed, and the search succeeds', function () {
    $spy = rerankSpy();
    $spy->fail = true;
    app()->singleton(Tracer::class);
    Event::fake([VectorRerankFailed::class, VectorOperationCompleted::class, VectorOperationFailed::class]);
    rerankCollection(10);
    $search = Vector::store('memory')->collection('rr')->usingVector([1.0, 0, 0, 0, 0, 0, 0, 0]);

    $hits = $search->rerank(using: 'spy', candidates: 8, query: 'reset')->take(3)->get()->hits;
    $span = app(Tracer::class)->spans[array_key_last(app(Tracer::class)->spans)][1];

    expect(array_map(fn (SearchHit $hit): string => (string) $hit->id, $hits))->toBe(['d0', 'd1', 'd2'])
        ->and($hits[0]->scores)->toBeNull()
        ->and($span['vector.rerank_status'])->toBe('failed_open')
        ->and(Event::dispatched(VectorOperationCompleted::class)->last()[0]->rerankMs)->toBeInt();
    Event::assertDispatched(VectorRerankFailed::class, fn (VectorRerankFailed $event): bool => $event->failedOpen && $event->reranker === 'spy' && $event->documentCount === 8 && $event->exception->getMessage() === 'reranker down');
    Event::assertNotDispatched(VectorOperationFailed::class);
});

it('throws with fail_open false and fires VectorOperationFailed with the rerank time', function () {
    $spy = rerankSpy();
    $spy->fail = true;
    config()->set('vector.rerank.fail_open', false);
    Event::fake([VectorRerankFailed::class, VectorOperationFailed::class]);
    rerankCollection(5);

    expect(fn () => Vector::store('memory')->collection('rr')->usingVector([1.0, 0, 0, 0, 0, 0, 0, 0])->rerank(using: 'spy', query: 'reset')->get())
        ->toThrow(RuntimeException::class, 'reranker down');
    Event::assertDispatched(VectorRerankFailed::class, fn (VectorRerankFailed $event): bool => ! $event->failedOpen);
    Event::assertDispatched(VectorOperationFailed::class, fn (VectorOperationFailed $event): bool => $event->operation === 'search' && $event->rerankMs !== null);
});

it('puts the v4 rerank keys on reranked search spans only, and rerankMs on the event', function () {
    rerankSpy();
    app()->singleton(Tracer::class);
    Event::fake([VectorOperationCompleted::class, VectorRerankCompleted::class]);
    rerankCollection(10);
    $search = Vector::store('memory')->collection('rr')->usingVector([1.0, 0, 0, 0, 0, 0, 0, 0]);
    $lastSpan = fn (): array => app(Tracer::class)->spans[array_key_last(app(Tracer::class)->spans)][1];

    $search->take(3)->get();
    $plain = $lastSpan();
    $search->rerank(using: 'spy', candidates: 7, query: 'reset')->take(3)->get();
    $reranked = $lastSpan();
    $events = Event::dispatched(VectorOperationCompleted::class)->filter(fn (array $args): bool => $args[0]->operation === 'search')->map(fn (array $args): ?int => $args[0]->rerankMs)->values()->all();

    expect($plain)->not->toHaveKeys(['vector.reranker', 'vector.rerank_ms', 'vector.rerank_candidates', 'vector.rerank_status'])
        ->and($reranked['vector.reranker'])->toBe('spy')
        ->and($reranked['vector.rerank_ms'])->toBeInt()
        ->and($reranked['vector.rerank_candidates'])->toBe(7)
        ->and($reranked['vector.rerank_status'])->toBe('ok')
        ->and($reranked['vector.top_k'])->toBe(3)
        ->and($events[0])->toBeNull()
        ->and($events[1])->toBeInt();
    Event::assertDispatched(VectorRerankCompleted::class, fn (VectorRerankCompleted $event): bool => $event->reranker === 'spy' && $event->model === 'spy-model' && $event->documentCount === 7 && $event->units === 1 && $event->tokens === null);
});

it('names the env key when rerank() has no name and no default is configured', function () {
    rerankCollection(3);

    expect(fn () => Vector::store('memory')->collection('rr')->rerank())
        ->toThrow(VectorRerankException::class, 'VECTOR_RERANK_PROVIDER')
        ->and(fn () => Vector::reranker())->toThrow(VectorRerankException::class, 'VECTOR_RERANK_PROVIDER')
        ->and(fn () => Vector::reranker('missing'))->toThrow(InvalidArgumentException::class, 'Reranker [missing] is not defined in vector.rerank.providers.');
});

it('needs a query text to rerank a usingVector() search, and candidates of at least 1', function () {
    rerankSpy();
    rerankCollection(3);
    $search = Vector::store('memory')->collection('rr')->usingVector([1.0, 0, 0, 0, 0, 0, 0, 0]);

    expect(fn () => $search->rerank(using: 'spy')->get())->toThrow(VectorQueryException::class, 'rerank(query: ...)')
        ->and(fn () => $search->rerank(using: 'spy', candidates: 0))->toThrow(VectorQueryException::class, 'at least 1');
});

it('asks the store for max(candidates, take) and sends no store-specific option', function () {
    rerankSpy();
    $search = Vector::store('memory')->collection('rr')->usingVector([1.0, 0, 0, 0, 0, 0, 0, 0]);

    $large = $search->rerank(using: 'spy', candidates: 100, query: 'q')->take(10)->compile();
    $small = $search->rerank(using: 'spy', candidates: 3, query: 'q')->take(10)->compile();

    expect([$large->topK, $large->options])->toBe([100, []])
        ->and([$small->topK, $small->options])->toBe([10, []]);
});

it('renames hybrid candidates to depth', function () {
    $search = Vector::store('memory')->collection('rr')->usingVector([1.0, 0, 0, 0, 0, 0, 0, 0])->keywords('q');

    expect($search->hybrid(depth: 80)->compile()->depth)->toBe(80)
        ->and(fn () => $search->hybrid(depth: 0))->toThrow(VectorQueryException::class, 'depth and k must be at least 1');
});

// Standalone and manager

it('reranks merged hits through Vector::reranker() with events and fail-open', function () {
    $spy = rerankSpy();
    Event::fake([VectorRerankCompleted::class, VectorRerankFailed::class]);

    $result = Vector::reranker('spy')->rerank('reset', rerankHits(4), 2);
    $spy->fail = true;
    $failed = Vector::reranker('spy')->rerank('reset', rerankHits(4), 2);

    expect(array_map(fn (SearchHit $hit): string => (string) $hit->id, $result->hits))->toBe(['h3', 'h2'])
        ->and($failed->failedOpen)->toBeTrue()
        ->and($failed->hits)->toEqual(array_slice(rerankHits(4), 0, 2))
        ->and(fn () => Vector::reranker('spy')->rerank('reset', rerankHits(2), 0))->toThrow(VectorQueryException::class, 'topN must be at least 1');
    Event::assertDispatchedTimes(VectorRerankCompleted::class, 1);
    Event::assertDispatchedTimes(VectorRerankFailed::class, 1);
});

it('gives each reranker the global timeout unless its entry sets one', function () {
    $seen = [];
    Vector::rerankers()->extend('probe', function ($app, array $config) use (&$seen): RerankerContract {
        $seen[] = $config['timeout'];

        return new DeterministicReranker;
    });
    config()->set('vector.rerank.timeout', 7);
    config()->set('vector.rerank.providers.a', ['driver' => 'probe']);
    config()->set('vector.rerank.providers.b', ['driver' => 'probe', 'timeout' => 2]);

    Vector::reranker('a');
    Vector::reranker('b');

    expect($seen)->toBe([7, 2]);
});

// Adapters

it('calls the Cohere Rerank API v2 and maps results and search units', function () {
    Http::fake(['api.cohere.com/*' => Http::response(['results' => [['index' => 2, 'relevance_score' => 0.9], ['index' => 0, 'relevance_score' => 0.4]], 'meta' => ['billed_units' => ['search_units' => 1]]])]);
    config()->set('vector.rerank.providers.cohere', ['driver' => 'cohere', 'model' => 'rerank-test', 'api_key' => 'test-key']);

    $result = Vector::reranker('cohere')->rerank('reset', rerankHits(3), 2);

    expect(array_map(fn (SearchHit $hit): string => (string) $hit->id, $result->hits))->toBe(['h2', 'h0'])
        ->and($result->hits[0]->scores)->toBe(['vector' => 0.98, 'rerank' => 0.9])
        ->and([$result->model, $result->units, $result->tokens])->toBe(['rerank-test', 1, null]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cohere.com/v2/rerank'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request->data() === ['model' => 'rerank-test', 'query' => 'reset', 'documents' => ['passage 0', 'passage 1', 'passage 2'], 'top_n' => 2]);
});

it('calls the Voyage rerank API and maps data and tokens', function () {
    Http::fake(['api.voyageai.com/*' => Http::response(['data' => [['index' => 1, 'relevance_score' => 0.7], ['index' => 0, 'relevance_score' => 0.2]], 'usage' => ['total_tokens' => 42]])]);
    config()->set('vector.rerank.providers.voyage', ['driver' => 'voyage', 'model' => 'rerank-test', 'api_key' => 'test-key']);

    $result = Vector::reranker('voyage')->rerank('reset', rerankHits(2), 5);

    expect(array_map(fn (SearchHit $hit): string => (string) $hit->id, $result->hits))->toBe(['h1', 'h0'])
        ->and([$result->units, $result->tokens])->toBe([null, 42]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.voyageai.com/v1/rerank' && $request['top_k'] === 2);
});

it('drops out-of-range and repeated indices and appends the hits a reranker left out', function () {
    Http::fake(['api.cohere.com/*' => Http::response(['results' => [['index' => 9, 'relevance_score' => 1.0], ['index' => 2, 'relevance_score' => 0.8], ['index' => 2, 'relevance_score' => 0.1], ['index' => 0, 'relevance_score' => 0.5]]])]);
    config()->set('vector.rerank.providers.cohere', ['driver' => 'cohere', 'model' => 'rerank-test', 'api_key' => 'test-key']);

    $hits = Vector::reranker('cohere')->rerank('reset', rerankHits(4), 4)->hits;

    expect(array_map(fn (SearchHit $hit): array => [(string) $hit->id, $hit->scores['rerank']], $hits))->toBe([['h2', 0.8], ['h0', 0.5], ['h1', null], ['h3', null]]);
});

it('fails open on an API timeout', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    Event::fake([VectorRerankFailed::class]);
    config()->set('vector.rerank.providers.cohere', ['driver' => 'cohere', 'model' => 'rerank-test', 'api_key' => 'test-key']);

    $result = Vector::reranker('cohere')->rerank('reset', rerankHits(3), 2);

    expect($result->failedOpen)->toBeTrue()
        ->and($result->hits)->toEqual(array_slice(rerankHits(3), 0, 2));
    Event::assertDispatched(VectorRerankFailed::class, fn (VectorRerankFailed $event): bool => $event->exception instanceof VectorRerankException && str_contains($event->exception->getMessage(), 'timed out'));
});

it('names the missing key and model of the API rerankers', function () {
    config()->set('vector.rerank.fail_open', false);
    config()->set('vector.rerank.providers.cohere', ['driver' => 'cohere', 'model' => 'rerank-test', 'api_key' => null]);
    config()->set('vector.rerank.providers.voyage', ['driver' => 'voyage', 'model' => null, 'api_key' => 'test-key']);

    expect(fn () => Vector::reranker('cohere')->rerank('q', rerankHits(1), 1))->toThrow(VectorRerankException::class, 'COHERE_API_KEY')
        ->and(fn () => Vector::reranker('voyage')->rerank('q', rerankHits(1), 1))->toThrow(VectorRerankException::class, 'VECTOR_RERANK_VOYAGE_MODEL');
});

it('ranks with Prism structured output, cuts passages, and validates the rankings', function () {
    $fake = Prism::fake([StructuredResponseFake::make()
        ->withStructured(['rankings' => [['index' => 1, 'score' => 90], ['index' => 7, 'score' => 99], ['index' => 1, 'score' => 10], ['index' => 0.5, 'score' => 80], ['index' => 2.0, 'score' => 40]]])
        ->withUsage(new Usage(100, 20))]);
    config()->set('vector.rerank.providers.llm', ['driver' => 'prism', 'provider' => 'openai', 'model' => 'test-model', 'max_characters' => 4, 'timeout' => 3]);

    $result = Vector::reranker('llm')->rerank('reset', rerankHits(4), 4);

    expect(array_map(fn (SearchHit $hit): array => [(string) $hit->id, $hit->scores['rerank']], $result->hits))->toBe([['h1', 90.0], ['h2', 40.0], ['h0', null], ['h3', null]])
        ->and([$result->model, $result->tokens])->toBe(['openai:test-model', 120]);
    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->prompt())->toContain("[0] pass\n\n[1] pass")->not->toContain('passage')
            ->and($requests[0]->temperature())->toBe(0)
            ->and($requests[0]->clientOptions())->toMatchArray(['timeout' => 3]);
    });
});

it('names the missing model and provider of the Prism reranker', function () {
    config()->set('vector.rerank.fail_open', false);
    config()->set('vector.rerank.providers.llm', ['driver' => 'prism', 'provider' => null, 'model' => null]);
    config()->set('vector.rerank.providers.llm2', ['driver' => 'prism', 'provider' => null, 'model' => 'no-provider']);

    expect(fn () => Vector::reranker('llm')->rerank('q', rerankHits(1), 1))->toThrow(VectorRerankException::class, 'VECTOR_RERANK_PRISM_MODEL')
        ->and(fn () => Vector::reranker('llm2')->rerank('q', rerankHits(1), 1))->toThrow(VectorRerankException::class, 'VECTOR_RERANK_PRISM_PROVIDER');
});
