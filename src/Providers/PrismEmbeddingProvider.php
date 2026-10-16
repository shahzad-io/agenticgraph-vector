<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Providers;

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\Events\VectorEmbeddingCompleted;
use AgenticGraph\Vector\Exceptions\VectorEmbeddingException;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Throwable;

final class PrismEmbeddingProvider implements EmbeddingProviderContract
{
    private ?int $resolvedDimensions = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function embed(string $text): array
    {
        $response = $this->requestEmbeddings([$text]);

        return $this->extractVectors($response)[0];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        return $this->extractVectors($this->requestEmbeddings($texts));
    }

    public function dimensions(): int
    {
        if ($this->resolvedDimensions !== null) {
            return $this->resolvedDimensions;
        }

        $configured = $this->configuredDimensions();

        if ($configured !== null) {
            return $configured;
        }

        $this->embed('dimension-probe');

        return $this->resolvedDimensions ?? throw new VectorEmbeddingException(
            'Unable to determine embedding dimensions from Prism.',
        );
    }

    public function model(): string
    {
        [, $model] = $this->parseProviderAndModel();

        return $model;
    }

    /**
     * One Prism request. Its token usage goes out on VectorEmbeddingCompleted.
     *
     * @param  list<string>  $texts
     */
    private function requestEmbeddings(array $texts): EmbeddingResponse
    {
        [$provider, $model] = $this->parseProviderAndModel();
        $started = hrtime(true);

        try {
            $request = Prism::embeddings()
                ->using($provider, $model, $this->providerConfig())
                ->fromArray($texts);

            $providerOptions = $this->embeddingProviderOptions();

            if ($providerOptions !== []) {
                $request = $request->withProviderOptions($providerOptions);
            }

            $response = $request->asEmbeddings();
        } catch (PrismException $exception) {
            throw new VectorEmbeddingException(
                'Embedding generation failed: '.$exception->getMessage(),
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new VectorEmbeddingException(
                'Embedding generation failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        VectorEmbeddingCompleted::record($model, count($texts), $response->usage->tokens, $started);

        return $response;
    }

    /**
     * @return list<list<float>>
     */
    private function extractVectors(EmbeddingResponse $response): array
    {
        if ($response->embeddings === []) {
            throw new VectorEmbeddingException('Prism returned no embeddings.');
        }

        $vectors = array_map(
            static function (Embedding $embedding): array {
                /** @var list<float> $values */
                $values = array_map(static fn (int|string|float $value): float => (float) $value, $embedding->embedding);

                return $values;
            },
            $response->embeddings,
        );

        foreach ($vectors as $vector) {
            $this->rememberDimensions($vector);
        }

        return $vectors;
    }

    /**
     * @param  list<float>  $vector
     */
    private function rememberDimensions(array $vector): void
    {
        $count = count($vector);

        if ($count === 0) {
            throw new VectorEmbeddingException('Prism returned an empty embedding vector.');
        }

        if ($this->resolvedDimensions === null) {
            $this->resolvedDimensions = $count;

            return;
        }

        if ($this->resolvedDimensions !== $count) {
            throw new VectorEmbeddingException('Prism returned inconsistent embedding dimensions.');
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseProviderAndModel(): array
    {
        $provider = $this->config['provider'] ?? null;
        $model = (string) ($this->config['model'] ?? '');

        if ($model === '') {
            throw new VectorEmbeddingException(
                'No embedding model configured. Set VECTOR_EMBEDDINGS_MODEL (vector.embeddings.providers.prism.model), '.
                'for example text-embedding-3-small, or switch VECTOR_EMBEDDINGS_PROVIDER to deterministic for tests.',
            );
        }

        if (is_string($provider) && $provider !== '') {
            return [$provider, $model];
        }

        [$parsedProvider, $parsedModel] = array_pad(explode(':', $model, 2), 2, '');

        if ($parsedProvider === '' || $parsedModel === '') {
            throw new VectorEmbeddingException(
                "Embedding model [{$model}] has no provider. Set VECTOR_EMBEDDINGS_PRISM_PROVIDER ".
                '(vector.embeddings.providers.prism.provider), for example openai, or use provider:model in VECTOR_EMBEDDINGS_MODEL.',
            );
        }

        return [$parsedProvider, $parsedModel];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfig(): array
    {
        $config = [];

        if (isset($this->config['api_key']) && is_string($this->config['api_key']) && $this->config['api_key'] !== '') {
            $config['api_key'] = $this->config['api_key'];
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddingProviderOptions(): array
    {
        $dimensions = $this->configuredDimensions();

        if ($dimensions === null) {
            return [];
        }

        return ['dimensions' => $dimensions];
    }

    private function configuredDimensions(): ?int
    {
        $configured = (int) ($this->config['dimensions'] ?? 0);

        return $configured > 0 ? $configured : null;
    }
}
