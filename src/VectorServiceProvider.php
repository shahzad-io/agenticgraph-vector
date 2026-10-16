<?php

declare(strict_types=1);

namespace AgenticGraph\Vector;

use AgenticGraph\Vector\Console\MigrateCommand;
use AgenticGraph\Vector\Support\MetadataValidator;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class VectorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vector')
            ->hasConfigFile()
            ->hasCommand(MigrateCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(MetadataValidator::class);

        $this->app->singleton(VectorManager::class, fn ($app): VectorManager => new VectorManager($app));
        $this->app->singleton(EmbeddingsManager::class, fn ($app): EmbeddingsManager => new EmbeddingsManager($app));

        $this->app->alias(VectorManager::class, 'vector');
        $this->app->alias(EmbeddingsManager::class, 'vector.embeddings');
    }
}
