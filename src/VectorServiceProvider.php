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
use stdClass;

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
        // Drivers added with extend(), kept for the life of the process: see KeepsExtensions.
        $this->app->singleton('vector.extensions', fn (): stdClass => new stdClass);

        // Scoped: rebuilt per Octane request and per queued job, so a cached store, provider or reranker never
        // outlives the request (and its config) it was built for. Within one request they are shared.
        $this->app->scoped(VectorManager::class, fn ($app): VectorManager => new VectorManager($app));
        $this->app->scoped(EmbeddingsManager::class, fn ($app): EmbeddingsManager => new EmbeddingsManager($app));
        $this->app->scoped(RerankManager::class, fn ($app): RerankManager => new RerankManager($app));

        $this->app->alias(VectorManager::class, 'vector');
        $this->app->alias(EmbeddingsManager::class, 'vector.embeddings');
        $this->app->alias(RerankManager::class, 'vector.rerank');
    }

    public function packageBooted(): void
    {
        // Octane clones the booted app for each request, so a manager resolved at boot (or listed in octane.warm)
        // would be every request's manager. Drop them from the clone. A string event name keeps Octane optional.
        $this->app['events']->listen('Laravel\Octane\Events\RequestReceived', function (object $event): void {
            foreach ([VectorManager::class, EmbeddingsManager::class, RerankManager::class] as $manager) {
                $event->sandbox->forgetInstance($manager);
            }
        });
    }
}
