<?php

declare(strict_types=1);

namespace AgenticGraph\Vector;

use AgenticGraph\Vector\Concerns\KeepsExtensions;
use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\Exceptions\VectorEmbeddingException;
use AgenticGraph\Vector\Providers\DeterministicEmbeddingProvider;
use AgenticGraph\Vector\Providers\PrismEmbeddingProvider;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves named providers from `vector.embeddings.providers`. Each entry names its `driver` (prism,
 * deterministic, or one registered with extend()), so one app can hold several Prism models.
 */
class EmbeddingsManager extends Manager
{
    use KeepsExtensions;

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('vector.embeddings.default', 'prism');
    }

    public function using(?string $name = null): EmbeddingProviderContract
    {
        return $this->driver($name);
    }

    /**
     * What a collection records about its embeddings: provider name, model and the collection's width.
     * Null when no provider is named and the default one has no model configured (a store fed only vectors).
     *
     * @return array{provider: string, model: string, dimensions: int}|null
     */
    public function record(?string $name, int $dimensions): ?array
    {
        try {
            return [
                'provider' => $name ?? (string) config('vector.embeddings.default', 'prism'),
                'model' => $this->using($name)->model(),
                'dimensions' => $dimensions,
            ];
        } catch (VectorEmbeddingException $exception) {
            if ($name !== null) {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * @param  string  $driver  the provider name
     */
    protected function createDriver($driver): mixed
    {
        if (isset($this->customCreators[$driver])) {
            return parent::createDriver($driver);
        }

        /** @var array<string, mixed>|null $config */
        $config = $this->config->get("vector.embeddings.providers.{$driver}");

        // A config published before named providers may lack these entries; both build from defaults.
        if ($config === null && ! in_array($driver, ['prism', 'deterministic'], true)) {
            throw new InvalidArgumentException("Embedding provider [{$driver}] is not defined in vector.embeddings.providers.");
        }

        $config ??= [];

        return match ((string) ($config['driver'] ?? $driver)) {
            'prism' => new PrismEmbeddingProvider($config),
            'deterministic' => new DeterministicEmbeddingProvider(
                dimensions: (int) ($config['dimensions'] ?? 8),
                modelName: (string) ($config['model'] ?? 'deterministic-test'),
            ),
            default => throw new InvalidArgumentException('Embedding provider driver ['.($config['driver'] ?? $driver)."] for provider [{$driver}] is not supported."),
        };
    }
}
