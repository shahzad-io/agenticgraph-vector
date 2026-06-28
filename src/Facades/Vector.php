<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Facades;

use AgenticGraph\Vector\Builder\Builder;
use AgenticGraph\Vector\Stores\PgVectorStore;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PgVectorStore store(?string $name = null)
 * @method static mixed driver(?string $driver = null)
 * @method static VectorManager extend(string $driver, \Closure(): \AgenticGraph\Vector\Contracts\SearchStoreContract $callback)
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
