<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Providers;

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\Support\ThrowsWhenNotImplemented;

final class PrismEmbeddingProvider implements EmbeddingProviderContract
{
    use ThrowsWhenNotImplemented;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function embed(string $text): array
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function embedBatch(array $texts): array
    {
        $this->throwNotImplemented(__METHOD__);
    }

    public function dimensions(): int
    {
        return (int) ($this->config['dimensions'] ?? 1536);
    }

    public function model(): string
    {
        return (string) ($this->config['model'] ?? 'text-embedding-3-small');
    }
}
