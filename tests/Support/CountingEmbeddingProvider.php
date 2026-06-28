<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;

final class CountingEmbeddingProvider implements EmbeddingProviderContract
{
    public int $embedBatchCallCount = 0;

    public function __construct(
        private readonly EmbeddingProviderContract $inner,
    ) {}

    public function embed(string $text): array
    {
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
}
