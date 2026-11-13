<?php

use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\QdrantTestHelper;
use AgenticGraph\Vector\VectorManager;
use AgenticGraph\Vector\VectorServiceProvider;

/** A config/vector.php published before qdrant, filter_plan and hybrid existed, with a few values of its own. */
function olderPublishedConfig(): array
{
    $published = require __DIR__.'/../../config/vector.php';
    unset($published['stores']['qdrant'], $published['stores']['pgvector']['filter_plan'], $published['hybrid']);
    $published['stores']['pgvector']['batch_size'] = 7;
    $published['queue']['backoff'] = [5];
    $published['stores']['legacy'] = ['driver' => 'memory'];

    return $published;
}

it('merges the store and section defaults under a published config, the published values winning', function () {
    $merged = VectorServiceProvider::mergeConfig(require __DIR__.'/../../config/vector.php', olderPublishedConfig());

    expect($merged['stores']['qdrant']['driver'])->toBe('qdrant')
        ->and($merged['stores']['qdrant']['batch_size'])->toBe(100)
        ->and($merged['stores']['pgvector']['filter_plan'])->toBe('auto')
        ->and($merged['stores']['pgvector']['batch_size'])->toBe(7)
        ->and($merged['stores']['legacy'])->toBe(['driver' => 'memory'])
        ->and($merged['hybrid'])->toBe(['match' => 'any', 'keyword_weight' => 0.25])
        ->and($merged['queue']['backoff'])->toBe([5]);
});

it('does not merge a default store into a published store of the same name with another driver', function () {
    $published = olderPublishedConfig();
    $published['stores']['qdrant'] = ['driver' => 'memory'];

    expect(VectorServiceProvider::mergeConfig(require __DIR__.'/../../config/vector.php', $published)['stores']['qdrant'])->toBe(['driver' => 'memory']);
});

it('serves the qdrant store from a published config that has no qdrant entry, once its env values are set', function () {
    putenv('VECTOR_QDRANT_URL='.QdrantTestHelper::url());

    try {
        config()->set('vector', olderPublishedConfig());
        $this->app->register(VectorServiceProvider::class, force: true);
        $this->app->forgetInstance(VectorManager::class);
        Vector::clearResolvedInstances();
    } finally {
        putenv('VECTOR_QDRANT_URL');
    }

    expect(config('vector.stores.qdrant.url'))->toBe(QdrantTestHelper::url())
        ->and(config('vector.stores.pgvector.batch_size'))->toBe(7);

    if (! QdrantTestHelper::isAvailable()) {
        $this->markTestSkipped(QdrantTestHelper::skipReason());
    }

    expect(Vector::store('qdrant')->listCollections())->toBeArray();
});
