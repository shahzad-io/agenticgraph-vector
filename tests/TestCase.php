<?php

namespace AgenticGraph\Vector\Tests;

use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\VectorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Prism\PrismServiceProvider;

class TestCase extends Orchestra
{
    /** @var list<string>|null registry names before the test; null when pgvector is not used */
    private ?array $collectionsBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collectionsBefore = PgvectorTestHelper::isAvailable() ? PgvectorTestHelper::collectionNames() : null;
    }

    protected function tearDown(): void
    {
        if ($this->collectionsBefore !== null) {
            PgvectorTestHelper::dropCollectionsNotIn($this->collectionsBefore);
        }

        // Close this test's connections now. Left to the garbage collector, old apps keep theirs open and a run
        // can pass Postgres max_connections (100), so later tests see pgvector as unavailable.
        foreach (array_keys($this->app['db']->getConnections()) as $connection) {
            $this->app['db']->purge($connection);
        }

        parent::tearDown();
    }

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
