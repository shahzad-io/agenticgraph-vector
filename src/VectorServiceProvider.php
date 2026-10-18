<?php

declare(strict_types=1);

namespace AgenticGraph\Vector;

use AgenticGraph\Vector\Console\IndexRebuildCommand;
use AgenticGraph\Vector\Console\IngestResumeCommand;
use AgenticGraph\Vector\Console\MigrateCommand;
use AgenticGraph\Vector\Console\TextSearchInstallCommand;
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
            ->hasMigration('create_vector_ingest_runs_table')
            ->hasCommand(MigrateCommand::class)
            ->hasCommand(IngestResumeCommand::class)
            ->hasCommand(IndexRebuildCommand::class)
            ->hasCommand(TextSearchInstallCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(MetadataValidator::class);

        $this->app->singleton(VectorManager::class, fn ($app): VectorManager => new VectorManager($app));
        $this->app->singleton(EmbeddingsManager::class, fn ($app): EmbeddingsManager => new EmbeddingsManager($app));
        $this->app->singleton(RerankManager::class, fn ($app): RerankManager => new RerankManager($app));

        $this->app->alias(VectorManager::class, 'vector');
        $this->app->alias(EmbeddingsManager::class, 'vector.embeddings');
        $this->app->alias(RerankManager::class, 'vector.rerank');
    }
}
