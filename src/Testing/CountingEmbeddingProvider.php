<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Testing;

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;

/**
 * Wraps a provider and counts its calls, to assert how often a store or builder embeds.
 */
final class CountingEmbeddingProvider implements EmbeddingProviderContract
{
    public int $embedCallCount = 0;

    public int $embedBatchCallCount = 0;

    public function __construct(
        private readonly EmbeddingProviderContract $inner,
    ) {}

    public function embed(string $text): array
    {
        $this->embedCallCount++;

        return $this->inner->embed($text);
    }

    public function embedBatch(array $texts): array
    {
        $this->embedBatchCallCount++;

        return $this->inner->embedBatch($texts);
    }

    public function dimensions(): int
    {
        return $this->inner->dimensions();
    }

    public function model(): string
    {
        return $this->inner->model();
    }

    public function reset(): void
    {
        $this->embedCallCount = 0;
        $this->embedBatchCallCount = 0;
    }
}
