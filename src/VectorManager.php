<?php

declare(strict_types=1);

namespace AgenticGraph\Vector;

use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Stores\PgVectorStore;
use AgenticGraph\Vector\Support\MetadataValidator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Manager;

class VectorManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('vector.default', 'pgvector');
    }

    public function store(?string $name = null): VectorStoreContract
    {
        return $this->driver($name);
    }

    protected function createPgvectorDriver(): PgVectorStore
    {
        /** @var array<string, mixed> $config */
        $config = $this->config->get('vector.stores.pgvector', []);

        return new PgVectorStore(
            config: $config,
            db: $this->container->make(DatabaseManager::class),
            embeddings: $this->container->make(EmbeddingsManager::class),
            metadataValidator: $this->container->make(MetadataValidator::class),
        );
    }
}
