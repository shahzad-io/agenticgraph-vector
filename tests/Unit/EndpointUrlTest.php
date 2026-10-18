<?php

use AgenticGraph\Vector\Providers\PrismEmbeddingProvider;
use AgenticGraph\Vector\Rerankers\PrismReranker;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use Illuminate\Support\Facades\Http;

function endpointFailureRun(Closure $run): void
{
    Http::fake(['*' => Http::response(['error' => ['message' => 'stop here']], 400)]);
    try {
        $run();
    } catch (Throwable) {
        // The request is what matters: it went out or it did not.
    }
}

it('sends the embedding request to the configured url', function () {
    endpointFailureRun(fn () => (new PrismEmbeddingProvider([
        'provider' => 'openai', 'model' => 'text-embedding-3-small', 'api_key' => 'k', 'url' => 'https://gateway.test/v1', 'retries' => 0,
    ]))->embed('hello'));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://gateway.test/v1/embeddings'));
});

it('keeps Prism\'s own base url when the embedding entry has no url', function () {
    endpointFailureRun(fn () => (new PrismEmbeddingProvider([
        'provider' => 'openai', 'model' => 'text-embedding-3-small', 'api_key' => 'k', 'url' => null, 'retries' => 0,
    ]))->embed('hello'));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.openai.com/'));
});

it('sends the Prism reranker request to the configured url', function () {
    endpointFailureRun(fn () => (new PrismReranker('llm', [
        'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => 'k', 'url' => 'https://gateway.test/v1', 'retries' => 0,
    ]))->rerank('q', [new SearchHit(id: '1', score: 0.5, content: 'text')], 1));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://gateway.test/v1/'));
});

it('keeps Prism\'s own base url when the reranker entry has no url', function () {
    endpointFailureRun(fn () => (new PrismReranker('llm', [
        'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => 'k', 'retries' => 0,
    ]))->rerank('q', [new SearchHit(id: '1', score: 0.5, content: 'text')], 1));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.openai.com/'));
});
