<?php

declare(strict_types=1);

use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\RerankManager;
use AgenticGraph\Vector\Stores\MemoryStore;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\VectorManager;

/**
 * Octane forgets scoped instances before each request, and a queue worker before each job. The managers cache
 * their resolved stores, embedding providers and rerankers with the config they were built from.
 */
it('rebuilds the managers for each request or queued job, with that request\'s config', function (string $manager) {
    $first = app($manager);
    app()->forgetScopedInstances();

    expect(app($manager))->not->toBe($first)
        ->and(app($manager))->toBe(app($manager));
})->with([VectorManager::class, EmbeddingsManager::class, RerankManager::class]);

it('gives each Octane request new managers, also when the app resolved or warmed them at boot', function (string $manager) {
    $booted = app($manager);
    $sandbox = clone app();

    event('Laravel\Octane\Events\RequestReceived', [(object) ['sandbox' => $sandbox]]);

    expect($sandbox->make($manager))->not->toBe($booted)
        ->and(app($manager))->toBe($booted);
})->with([VectorManager::class, EmbeddingsManager::class, RerankManager::class]);

it('keeps drivers added with extend() at boot on the managers of later requests and jobs', function () {
    Vector::extend('boot-store', fn ($app, array $config) => new MemoryStore($config, app(EmbeddingsManager::class), app(MetadataValidator::class)));
    config(['vector.stores.extended' => ['driver' => 'boot-store']]);
    app()->forgetScopedInstances();
    Vector::clearResolvedInstances();

    expect(Vector::store('extended')->collectionExists('none'))->toBeFalse();
});
