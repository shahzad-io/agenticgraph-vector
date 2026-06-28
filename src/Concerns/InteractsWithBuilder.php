<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Concerns;

use AgenticGraph\Vector\Builder\Builder;
use AgenticGraph\Vector\Contracts\SearchStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;

trait InteractsWithBuilder
{
    public function collection(string $name): Builder
    {
        return new Builder(
            store: $this,
            collection: $name,
            embeddings: app(EmbeddingsManager::class),
        );
    }
}
