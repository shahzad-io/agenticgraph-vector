<?php

use AgenticGraph\Vector\Events\VectorEmbeddingCompleted;
use AgenticGraph\Vector\Events\VectorRerankFailed;
use AgenticGraph\Vector\Exceptions\VectorEmbeddingException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Providers\PrismEmbeddingProvider;
use AgenticGraph\Vector\Support\HttpRetry;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\ValueObjects\Embedding;

/*
 * Retries and timeouts of embedding and rerank requests. The embedding tests go through Prism's
 * real OpenAI provider against Http::fake, so the retry decision sees the raw HTTP exception.
 */

beforeEach(function () {
    config()->set('prism.providers.openai.api_key', 'test-key');
    config()->set('prism.providers.openai.url', 'https://api.openai.com/v1');
    Sleep::fake();
    Event::fake([VectorEmbeddingCompleted::class, VectorRerankFailed::class]);
});

function retryEmbeddingOk(): array
{
    return ['data' => [['embedding' => [0.1, 0.2, 0.3]]], 'usage' => ['total_tokens' => 4], 'model' => 'text-embedding-3-small'];
}

function retryProvider(array $config = []): PrismEmbeddingProvider
{
    return new PrismEmbeddingProvider(['provider' => 'openai', 'model' => 'text-embedding-3-small', ...$config]);
}

function retryEmbeddingFailure(callable $call): VectorEmbeddingException
{
    try {
        $call();
    } catch (VectorEmbeddingException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a VectorEmbeddingException.');
}

it('retries a retryable embedding error and fires one VectorEmbeddingCompleted', function (callable $first) {
    Http::fake(['api.openai.com/*' => Http::sequence()->pushResponse($first())->push(retryEmbeddingOk())]);

    expect(retryProvider()->embed('hello'))->toBe([0.1, 0.2, 0.3]);

    Http::assertSentCount(2);
    Event::assertDispatchedTimes(VectorEmbeddingCompleted::class, 1);
    Sleep::assertSleptTimes(1);
})->with([
    '429' => [fn () => Http::response(['error' => ['message' => 'rate limited']], 429)],
    '503' => [fn () => Http::response(['error' => ['message' => 'unavailable']], 503)],
    '529' => [fn () => Http::response(['error' => ['message' => 'overloaded']], 529)],
    'connection error' => [fn () => Http::failedConnection('cURL error 28: Operation timed out')],
]);

it('does not retry other client errors and marks them not retryable', function (int $status) {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad']], $status)]);

    $exception = retryEmbeddingFailure(fn () => retryProvider()->embed('hello'));

    expect([$exception->retryable, $exception->status])->toBe([false, $status]);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
    Event::assertNotDispatched(VectorEmbeddingCompleted::class);
})->with([400, 401, 413]);

it('gives up after the configured retries with a retryable exception and its status', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 503)]);

    $exception = retryEmbeddingFailure(fn () => retryProvider(['retries' => 3, 'retry_sleep_ms' => 100])->embed('hello'));

    expect([$exception->retryable, $exception->status])->toBe([true, 503]);
    Http::assertSentCount(4);
    Sleep::assertSleptTimes(3);
});

it('marks a 429 retryable even though Prism maps it without the HTTP exception', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'slow down']], 429)]);

    $exception = retryEmbeddingFailure(fn () => retryProvider(['retries' => 0])->embed('hello'));

    expect([$exception->retryable, $exception->status])->toBe([true, 429]);
    Http::assertSentCount(1);
});

it('honors a Retry-After up to retry_after_max_ms on an inline embedding call', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'slow down']], 429, ['Retry-After' => '1'])
        ->push(retryEmbeddingOk())]);

    expect(retryProvider()->embed('hello'))->toBe([0.1, 0.2, 0.3]);

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(1000)->milliseconds()]);
});

it('fails a 429 whose Retry-After is above retry_after_max_ms as retryable instead of sleeping', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'slow down']], 429, ['Retry-After' => '2.5'])]);

    $exception = retryEmbeddingFailure(fn () => retryProvider(['retries' => 2, 'retry_sleep_ms' => 500])->embed('hello'));

    expect([$exception->retryable, $exception->status])->toBe([true, 429]);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('honors a reranker Retry-After that fits its budget, and fails open on one that does not', function (string $retryAfter, bool $failedOpen) {
    Http::fake(['api.cohere.com/*' => Http::sequence()
        ->push(['message' => 'busy'], 429, ['Retry-After' => $retryAfter])
        ->push(['results' => [['index' => 0, 'relevance_score' => 0.9]], 'meta' => ['billed_units' => ['search_units' => 1]]])]);
    config()->set('vector.rerank.providers.cohere', ['driver' => 'cohere', 'model' => 'rerank-test', 'api_key' => 'k', 'timeout' => 3]);

    expect(Vector::reranker('cohere')->rerank('q', retryHits(2), 2)->failedOpen)->toBe($failedOpen);
    Http::assertSentCount($failedOpen ? 1 : 2);
})->with([
    '2 s inside a 3 s budget' => ['2', false],
    '5 s past a 3 s budget' => ['5', true],
]);

it('marks a connection error retryable without a status', function () {
    Http::fake(['api.openai.com/*' => Http::failedConnection('cURL error 7: Failed to connect')]);

    $exception = retryEmbeddingFailure(fn () => retryProvider(['retries' => 1])->embed('hello'));

    expect([$exception->retryable, $exception->status])->toBe([true, null]);
    Http::assertSentCount(2);
});

it('sends the timeout and retry count through Prism client options, with explicit defaults', function () {
    $fake = Prism::fake([
        EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray([0.1])]),
        EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray([0.1])]),
    ]);

    retryProvider()->embed('defaults');
    retryProvider(['timeout' => 12, 'retries' => 4])->embed('configured');

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->clientOptions())->toBe(['timeout' => 30, 'connect_timeout' => 30])
            ->and($requests[0]->clientRetry()[0])->toBe(3)
            ->and($requests[1]->clientOptions())->toBe(['timeout' => 12, 'connect_timeout' => 12])
            ->and($requests[1]->clientRetry()[0])->toBe(5);
    });
});

it('waits half to all of the exponential ceiling, or Retry-After when longer', function () {
    $retryAfter = fn (string $seconds) => new RequestException(new Response(new GuzzleHttp\Psr7\Response(429, ['Retry-After' => $seconds])));

    foreach (range(1, 50) as $ignored) {
        expect(HttpRetry::delayMs(1, 500))->toBeBetween(250, 500)
            ->and(HttpRetry::delayMs(3, 500))->toBeBetween(1000, 2000);
    }

    // Retry-After wins only above the drawn wait: exact above the ceiling, at least Retry-After inside the window.
    expect(HttpRetry::delayMs(3, 500, $retryAfter('2.5')))->toBe(2500)
        ->and(HttpRetry::delayMs(1, 500, $retryAfter('1.5')))->toBe(1500)
        ->and(HttpRetry::delayMs(3, 500, $retryAfter('1.9')))->toBeBetween(1900, 2000)
        ->and(HttpRetry::shouldRetry($retryAfter('2'), 2000))->toBeTrue()
        ->and(HttpRetry::shouldRetry($retryAfter('2.1'), 2000))->toBeFalse()
        ->and(HttpRetry::shouldRetry($retryAfter('20'), 30000))->toBeTrue();
});

it('retries a reranker within its total timeout', function () {
    Http::fake(['api.cohere.com/*' => Http::sequence()
        ->push(['message' => 'busy'], 503)
        ->push(['results' => [['index' => 1, 'relevance_score' => 0.9], ['index' => 0, 'relevance_score' => 0.1]], 'meta' => ['billed_units' => ['search_units' => 1]]])]);
    config()->set('vector.rerank.providers.cohere', ['driver' => 'cohere', 'model' => 'rerank-test', 'api_key' => 'k']);

    $result = Vector::reranker('cohere')->rerank('q', retryHits(2), 2);

    expect(array_map(fn (SearchHit $hit): string => (string) $hit->id, $result->hits))->toBe(['h1', 'h0'])
        ->and($result->failedOpen)->toBeFalse();
    Http::assertSentCount(2);
});

it('stops reranker retries when the next wait would pass the total timeout', function () {
    // Retry-After above each jittered wait makes the waits exact: 600 ms fits the 1 s budget, 1200 ms does not.
    Http::fake(['api.cohere.com/*' => Http::sequence()
        ->push(['message' => 'busy'], 503, ['Retry-After' => '0.6'])
        ->push(['message' => 'busy'], 503, ['Retry-After' => '1.2'])]);
    config()->set('vector.rerank.timeout', 1);
    config()->set('vector.rerank.retries', 5);
    config()->set('vector.rerank.retry_sleep_ms', 600);
    config()->set('vector.rerank.providers.cohere', ['driver' => 'cohere', 'model' => 'rerank-test', 'api_key' => 'k']);

    $result = Vector::reranker('cohere')->rerank('q', retryHits(3), 2);

    expect($result->failedOpen)->toBeTrue()
        ->and(array_map(fn (SearchHit $hit): string => (string) $hit->id, $result->hits))->toBe(['h0', 'h1']);
    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(600)->milliseconds()]);
    Event::assertDispatched(VectorRerankFailed::class);
});

it('does not retry a reranker client error and gives each request what is left of the budget', function () {
    $timeouts = [];
    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'];

        return Http::response(['message' => 'bad'], count($timeouts) === 1 ? 500 : 400);
    });
    config()->set('vector.rerank.providers.cohere', ['driver' => 'cohere', 'model' => 'rerank-test', 'api_key' => 'k', 'timeout' => 4, 'retries' => 5]);

    expect(Vector::reranker('cohere')->rerank('q', retryHits(2), 2)->failedOpen)->toBeTrue()
        ->and($timeouts)->toHaveCount(2)
        ->and($timeouts[0])->toBeLessThanOrEqual(4.0)->toBeGreaterThan(3.9)
        ->and($timeouts[1])->toBeLessThan($timeouts[0]);
});

it('retries the Prism reranker on a 429 that Prism maps without the HTTP exception', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'slow down']], 429)
        ->push(['error' => ['message' => 'bad request']], 400)]);
    config()->set('vector.rerank.providers.llm', ['driver' => 'prism', 'provider' => 'openai', 'model' => 'gpt-test']);

    expect(Vector::reranker('llm')->rerank('q', retryHits(2), 2)->failedOpen)->toBeTrue();
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});

function retryHits(int $count): array
{
    return array_map(fn (int $i): SearchHit => new SearchHit("h{$i}", 1 - $i / 10, "passage {$i}"), range(0, $count - 1));
}
