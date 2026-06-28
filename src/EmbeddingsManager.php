<?php

declare(strict_types=1);

namespace AgenticGraph\Vector;

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\Providers\PrismEmbeddingProvider;
use Illuminate\Support\Manager;

class EmbeddingsManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('vector.embeddings.default', 'prism');
    }

    public function using(?string $name = null): EmbeddingProviderContract
    {
        return $this->driver($name);
    }

    protected function createPrismDriver(): PrismEmbeddingProvider
    {
        /** @var array<string, mixed> $config */
        $config = $this->config->get('vector.embeddings.providers.prism', []);

        return new PrismEmbeddingProvider($config);
    }
}
