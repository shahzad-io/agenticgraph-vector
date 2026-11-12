<?php

declare(strict_types=1);

namespace AgenticGraph\Vector;

use AgenticGraph\Vector\Concerns\KeepsExtensions;
use AgenticGraph\Vector\Contracts\IndexStoreContract;
use AgenticGraph\Vector\Contracts\MigrationStoreContract;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Ingest\QueuedIngest;
use AgenticGraph\Vector\Migration\CollectionMigrator;
use AgenticGraph\Vector\Rerankers\InstrumentedReranker;
use AgenticGraph\Vector\Stores\InstrumentedStore;
use AgenticGraph\Vector\Stores\MemoryStore;
use AgenticGraph\Vector\Stores\PgVectorStore;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\Tracing\VectorTraceContext;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\IngestRun;
use AgenticGraph\Vector\ValueObjects\MigrationState;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves named stores from `vector.stores`. Each entry names its `driver` (pgvector, memory, or one
 * registered with extend()). Every VectorStoreContract is returned wrapped in an InstrumentedStore.
 */
class VectorManager extends Manager
{
    use KeepsExtensions;

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('vector.default', 'pgvector');
    }

    public function store(?string $name = null): VectorStoreContract
    {
        return $this->driver($name);
    }

    /**
     * A named reranker from `vector.rerank.providers` (null: `vector.rerank.default`), for reranking hits merged
     * from several searches. Fail-open and events apply as in rerank() on the builder.
     */
    public function reranker(?string $name = null): InstrumentedReranker
    {
        return $this->rerankers()->reranker($name);
    }

    public function rerankers(): RerankManager
    {
        return $this->container->make(RerankManager::class);
    }

    /**
     * A queued ingest's run record (the id upsert() returned after queue()); null when unknown.
     */
    public function ingestRun(string $id): ?IngestRun
    {
        return QueuedIngest::find($id);
    }

    /**
     * Move $alias to a new collection with $definition (its name is ignored): adopt a plain collection,
     * copy or re-embed, catch up writes, switch. The old collection stays until dropOldCollection().
     * A rerun resumes an unfinished migration. One `vector.migrate` span.
     *
     * @param  string|null  $mode  copy or reembed; required when the source has no recorded embeddings
     */
    public function migrate(string $alias, CollectionDefinition $definition, ?string $store = null, bool $dryRun = false, ?string $mode = null, ?int $batchSize = null): MigrationState
    {
        $name = $store ?? $this->getDefaultDriver();
        $migrator = $this->migrator($name);

        return TraceSpan::operation(
            'migrate',
            fn (): MigrationState => $migrator->migrate($alias, $definition, $dryRun, $mode, $batchSize),
            VectorTraceContext::forCollection($alias, $name),
        );
    }

    /**
     * Point $alias back at the collection its last migration came from.
     */
    public function rollbackMigration(string $alias, ?string $store = null): MigrationState
    {
        return $this->migrator($store ?? $this->getDefaultDriver())->rollback($alias);
    }

    /**
     * Delete the collection $alias's last migration came from (no rollback after this).
     */
    /**
     * Rebuild a collection's embedding index from its rows, without blocking searches or writes (capability
     * RebuildIndex). Needed after the first bulk load of an IVFFlat collection. Throws VectorQueryException while
     * another rebuild of the same collection runs.
     */
    public function rebuildIndex(string $collection, ?string $store = null): void
    {
        $instance = $this->store($store);

        if (! $instance instanceof IndexStoreContract) {
            throw new VectorUnsupportedOperationException('Store ['.($store ?? $this->getDefaultDriver()).'] has no index to rebuild.');
        }

        $instance->rebuildIndex($collection);
    }

    public function dropOldCollection(string $alias, ?string $store = null): MigrationState
    {
        return $this->migrator($store ?? $this->getDefaultDriver())->dropOld($alias);
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
            $driverName === 'pgvector' => $this->createPgvectorStore($config, $name),
            $driverName === 'memory' => $this->createMemoryStore($config),
            default => throw new InvalidArgumentException("Vector store driver [{$driverName}] for store [{$name}] is not supported."),
        };

        return $store instanceof VectorStoreContract ? new InstrumentedStore($name, $store) : $store;
    }

    private function migrator(string $name): CollectionMigrator
    {
        $store = $this->store($name);

        if (! $store instanceof MigrationStoreContract || ! $store->capabilities()->supports(StoreCapability::Migrations)) {
            throw new VectorUnsupportedOperationException("Migrations are not supported by store [{$name}].");
        }

        return new CollectionMigrator($store, $name, $this->container->make(EmbeddingsManager::class));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createPgvectorStore(array $config, string $name): PgVectorStore
    {
        return new PgVectorStore(
            config: $config,
            db: $this->container->make(DatabaseManager::class),
            embeddings: $this->container->make(EmbeddingsManager::class),
            metadataValidator: $this->container->make(MetadataValidator::class),
            name: $name,
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
