<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Rerankers;

use AgenticGraph\Vector\Contracts\RerankerContract;
use AgenticGraph\Vector\Exceptions\VectorRerankException;
use AgenticGraph\Vector\Support\HttpRetry;
use AgenticGraph\Vector\Support\RerankOrder;
use AgenticGraph\Vector\ValueObjects\RerankResult;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A dedicated rerank API: Cohere (Rerank API v2, billed in search units) or Voyage (billed in tokens). Both
 * take a query and the hit contents and answer with (index, relevance_score) pairs.
 */
final class ApiReranker implements RerankerContract
{
    private const VENDORS = [
        'cohere' => ['url' => 'https://api.cohere.com/v2/rerank', 'top' => 'top_n', 'results' => 'results', 'key_env' => 'COHERE_API_KEY'],
        'voyage' => ['url' => 'https://api.voyageai.com/v1/rerank', 'top' => 'top_k', 'results' => 'data', 'key_env' => 'VOYAGE_API_KEY'],
    ];

    /**
     * @param  'cohere'|'voyage'  $vendor
     * @param  array<string, mixed>  $config  the provider entry: model, api_key, timeout (total seconds, retries included), retries, retry_sleep_ms
     */
    public function __construct(
        private readonly string $vendor,
        private readonly string $name,
        private readonly array $config,
    ) {}

    public function rerank(string $query, array $hits, int $topN): RerankResult
    {
        $model = $this->model();

        if ($hits === []) {
            return new RerankResult([], $model);
        }

        $vendor = self::VENDORS[$this->vendor];
        $key = (string) ($this->config['api_key'] ?? '');

        if ($key === '') {
            throw new VectorRerankException("Reranker [{$this->name}] has no API key. Set {$vendor['key_env']} (vector.rerank.providers.{$this->name}.api_key).");
        }

        try {
            $json = HttpRetry::withinBudget(
                (float) ($this->config['timeout'] ?? 5),
                (int) ($this->config['retries'] ?? 2),
                (int) ($this->config['retry_sleep_ms'] ?? 500),
                fn (float $seconds): mixed => Http::withToken($key)->acceptJson()->timeout($seconds)->connectTimeout($seconds)
                    ->post($vendor['url'], [
                        'model' => $model,
                        'query' => $query,
                        'documents' => array_map(static fn (SearchHit $hit): string => $hit->content, $hits),
                        $vendor['top'] => min($topN, count($hits)),
                    ])
                    ->throw()
                    ->json(),
            );
        } catch (Throwable $exception) {
            throw new VectorRerankException("Reranker [{$this->name}] failed: {$exception->getMessage()}", previous: $exception);
        }

        $ranked = array_map(
            static fn (array $result): array => ['index' => $result['index'] ?? null, 'score' => $result['relevance_score'] ?? null],
            array_filter((array) ($json[$vendor['results']] ?? []), 'is_array'),
        );

        return new RerankResult(
            hits: RerankOrder::apply($hits, $ranked, $topN),
            model: $model,
            units: $this->vendor === 'cohere' ? self::intOrNull($json['meta']['billed_units']['search_units'] ?? null) : null,
            tokens: $this->vendor === 'voyage' ? self::intOrNull($json['usage']['total_tokens'] ?? null) : null,
        );
    }

    private function model(): string
    {
        $model = (string) ($this->config['model'] ?? '');

        if ($model === '') {
            $env = 'VECTOR_RERANK_'.strtoupper($this->vendor).'_MODEL';

            throw new VectorRerankException("Reranker [{$this->name}] has no model. Set vector.rerank.providers.{$this->name}.model ({$env} in the published config).");
        }

        return $model;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
