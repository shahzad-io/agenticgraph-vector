<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Providers;

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;

/**
 * Deterministic, API-free embedding provider for fast unit and integration tests.
 */
final class DeterministicEmbeddingProvider implements EmbeddingProviderContract
{
    public function __construct(
        private readonly int $dimensions = 8,
        private readonly string $modelName = 'deterministic-test',
    ) {}

    public function embed(string $text): array
    {
        return $this->vectorFor($text);
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn (string $text): array => $this->vectorFor($text), $texts);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function model(): string
    {
        return $this->modelName;
    }

    /**
     * @return list<float>
     */
    private function vectorFor(string $text): array
    {
        $hash = hash('sha256', $text);
        $vector = [];

        for ($i = 0; $i < $this->dimensions; $i++) {
            $offset = ($i * 2) % strlen($hash);
            $vector[] = (hexdec(substr($hash, $offset, 2)) / 255.0) * 2 - 1;
        }

        $magnitude = sqrt(array_sum(array_map(static fn (float $value): float => $value ** 2, $vector)));

        if ($magnitude === 0.0) {
            $vector[0] = 1.0;

            return $vector;
        }

        return array_map(static fn (float $value): float => $value / $magnitude, $vector);
    }
}
