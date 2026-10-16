<?php

declare(strict_types=1);

namespace AgenticGraph\Vector;

use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\Stores\InstrumentedStore;
use AgenticGraph\Vector\Stores\MemoryStore;
use AgenticGraph\Vector\Stores\PgVectorStore;
use AgenticGraph\Vector\Support\MetadataValidator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves named stores from `vector.stores`. Each entry names its `driver` (pgvector, memory, or one
 * registered with extend()). Every VectorStoreContract is returned wrapped in an InstrumentedStore.
 */
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

    /**
     * @param  string  $driver  the store name
     */
    protected function createDriver($driver): mixed
    {
        $name = $driver;

        /** @var array<string, mixed>|null $config */
        $config = $this->config->get("vector.stores.{$name}");

        if ($config === null && ! isset($this->customCreators[$name])) {
            throw new InvalidArgumentException("Vector store [{$name}] is not defined in vector.stores.");
        }

        $config ??= [];
        $driverName = (string) ($config['driver'] ?? $name);

        $store = match (true) {
            isset($this->customCreators[$driverName]) => $this->customCreators[$driverName]($this->container, $config, $name),
            $driverName === 'pgvector' => $this->createPgvectorStore($config),
            $driverName === 'memory' => $this->createMemoryStore($config),
            default => throw new InvalidArgumentException("Vector store driver [{$driverName}] for store [{$name}] is not supported."),
        };

        return $store instanceof VectorStoreContract ? new InstrumentedStore($name, $store) : $store;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createPgvectorStore(array $config): PgVectorStore
    {
        return new PgVectorStore(
            config: $config,
            db: $this->container->make(DatabaseManager::class),
            embeddings: $this->container->make(EmbeddingsManager::class),
            metadataValidator: $this->container->make(MetadataValidator::class),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createMemoryStore(array $config): MemoryStore
    {
        return new MemoryStore(
            config: $config,
            embeddings: $this->container->make(EmbeddingsManager::class),
            metadataValidator: $this->container->make(MetadataValidator::class),
        );
    }
}
