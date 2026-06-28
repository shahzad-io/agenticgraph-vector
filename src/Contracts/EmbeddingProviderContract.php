<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

interface EmbeddingProviderContract
{
    /**
     * @return list<float>
     */
    public function embed(string $text): array;

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array;

    public function dimensions(): int;

    public function model(): string;
}
