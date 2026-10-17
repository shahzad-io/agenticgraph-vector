<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Rerankers;

use AgenticGraph\Vector\Contracts\RerankerContract;
use AgenticGraph\Vector\Exceptions\VectorRerankException;
use AgenticGraph\Vector\Support\RerankOrder;
use AgenticGraph\Vector\ValueObjects\RerankResult;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Throwable;

/**
 * An LLM reranker through Prism structured output: one request scores every passage 0 to 100. Slower and
 * usually dearer than a rerank API, but works with any Prism provider that supports structured output.
 */
final class PrismReranker implements RerankerContract
{
    private const SYSTEM = 'You rank search results. Score how well each passage answers the query, from 0 (unrelated) to 100 (answers it fully). '
        .'Return one entry per passage with its index and score. Judge only the passage text.';

    /**
     * @param  array<string, mixed>  $config  the provider entry: provider, model (or provider:model), api_key, max_characters, timeout (seconds)
     */
    public function __construct(
        private readonly string $name,
        private readonly array $config,
    ) {}

    public function rerank(string $query, array $hits, int $topN): RerankResult
    {
        [$provider, $model] = $this->providerAndModel();

        if ($hits === []) {
            return new RerankResult([], "{$provider}:{$model}");
        }

        $maxCharacters = max(1, (int) ($this->config['max_characters'] ?? 1500));
        $passages = implode("\n\n", array_map(
            static fn (int $index, SearchHit $hit): string => "[{$index}] ".mb_substr($hit->content, 0, $maxCharacters),
            array_keys($hits),
            $hits,
        ));
        $timeout = (int) ($this->config['timeout'] ?? 5);
        $apiKey = (string) ($this->config['api_key'] ?? '');

        try {
            $response = Prism::structured()
                ->using($provider, $model, $apiKey !== '' ? ['api_key' => $apiKey] : [])
                ->withSchema(new ObjectSchema('rankings', 'Relevance of each passage to the query', [
                    new ArraySchema('rankings', 'One entry per passage', new ObjectSchema('ranking', 'A passage and its score', [
                        new NumberSchema('index', 'The passage number in brackets'),
                        new NumberSchema('score', 'Relevance from 0 to 100'),
                    ], ['index', 'score'])),
                ], ['rankings']))
                ->withSystemPrompt(self::SYSTEM)
                ->withPrompt("Query: {$query}\n\nPassages:\n{$passages}")
                ->usingTemperature(0)
                ->withClientOptions(['timeout' => $timeout, 'connect_timeout' => $timeout])
                ->asStructured();
        } catch (Throwable $exception) {
            throw new VectorRerankException("Reranker [{$this->name}] failed: {$exception->getMessage()}", previous: $exception);
        }

        $rankings = $response->structured['rankings'] ?? null;

        if (! is_array($rankings)) {
            throw new VectorRerankException("Reranker [{$this->name}] returned no rankings.");
        }

        return new RerankResult(
            hits: RerankOrder::apply($hits, array_filter($rankings, static fn (mixed $entry): bool => is_array($entry) && array_key_exists('index', $entry) && array_key_exists('score', $entry)), $topN),
            model: "{$provider}:{$model}",
            tokens: $response->usage->promptTokens + $response->usage->completionTokens,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function providerAndModel(): array
    {
        $provider = (string) ($this->config['provider'] ?? '');
        $model = (string) ($this->config['model'] ?? '');

        if ($model === '') {
            throw new VectorRerankException("Reranker [{$this->name}] has no model. Set vector.rerank.providers.{$this->name}.model (VECTOR_RERANK_PRISM_MODEL in the published config), for example openai:gpt-4.1-mini.");
        }

        if ($provider !== '') {
            return [$provider, $model];
        }

        [$parsedProvider, $parsedModel] = array_pad(explode(':', $model, 2), 2, '');

        if ($parsedProvider === '' || $parsedModel === '') {
            throw new VectorRerankException("Reranker [{$this->name}] model [{$model}] has no provider. Set vector.rerank.providers.{$this->name}.provider (VECTOR_RERANK_PRISM_PROVIDER), or use provider:model.");
        }

        return [$parsedProvider, $parsedModel];
    }
}
