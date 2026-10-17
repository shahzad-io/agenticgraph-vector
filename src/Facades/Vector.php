<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Facades;

use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\Rerankers\InstrumentedReranker;
use AgenticGraph\Vector\RerankManager;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\IngestRun;
use AgenticGraph\Vector\ValueObjects\MigrationState;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Facade;

/**
 * @method static VectorStoreContract store(?string $name = null)
 * @method static InstrumentedReranker reranker(?string $name = null)
 * @method static RerankManager rerankers()
 * @method static MigrationState migrate(string $alias, CollectionDefinition $definition, ?string $store = null, bool $dryRun = false, ?string $mode = null, ?int $batchSize = null)
 * @method static MigrationState rollbackMigration(string $alias, ?string $store = null)
 * @method static MigrationState dropOldCollection(string $alias, ?string $store = null)
 * @method static IngestRun|null ingestRun(string $id)
 * @method static void rebuildIndex(string $collection, ?string $store = null)
 * @method static mixed driver(?string $driver = null)
 * @method static VectorManager extend(string $driver, \Closure(Container, array<string, mixed>, string): VectorStoreContract $callback)
 *
 * @see VectorManager
 */
class Vector extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VectorManager::class;
    }
}
