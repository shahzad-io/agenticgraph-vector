<?php

namespace AgenticGraph\Vector\Tests;

use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\VectorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Prism\PrismServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            VectorServiceProvider::class,
            PrismServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        $app['config']->set('vector', require __DIR__.'/../config/vector.php');
        $app['config']->set('vector.embeddings.default', 'deterministic');

        if (PgvectorTestHelper::isAvailable()) {
            $app['config']->set('database.default', 'pgvector');
            $app['config']->set('database.connections.pgvector', PgvectorTestHelper::connectionConfig());
            $app['config']->set('vector.stores.pgvector.connection', 'pgvector');

            return;
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
