<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Facades;

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\EmbeddingsManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static EmbeddingProviderContract using(?string $name = null)
 * @method static mixed driver(?string $driver = null)
 * @method static EmbeddingsManager extend(string $driver, \Closure(): EmbeddingProviderContract $callback)
 *
 * @see EmbeddingsManager
 */
class Embeddings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EmbeddingsManager::class;
    }
}
