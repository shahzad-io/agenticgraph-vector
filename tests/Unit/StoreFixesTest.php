<?php

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Exceptions\VectorConnectionException;
use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Stores\MemoryStore;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use Illuminate\Support\Facades\Event;

it('gives each pgvector store the batch size of its own config entry', function () {
    config()->set('vector.stores.pgvector.batch_size', 999);
    config()->set('vector.stores.pg_a', ['driver' => 'pgvector', 'batch_size' => 7]);
    config()->set('vector.stores.pg_b', ['driver' => 'pgvector']);
    config()->set('vector.batch.size', 11);

    expect(Vector::store('pg_a')->batchSize())->toBe(7)
        ->and(Vector::store('pg_b')->batchSize())->toBe(11)
        ->and(Vector::store('pgvector')->batchSize())->toBe(999);
});

it('names an unnamed store as unnamed on events, never vector.default', function () {
    config()->set('vector.default', 'pgvector');
    Event::fake([VectorOperationCompleted::class]);

    $store = new MemoryStore([], app(EmbeddingsManager::class), new MetadataValidator);
    $store->createCollection(new CollectionDefinition('articles', dimensions: 2));
    $store->collection('articles')->usingVector([1.0, 0.0])->get();

    Event::assertDispatched(VectorOperationCompleted::class, fn (VectorOperationCompleted $event): bool => $event->store === 'unnamed');
});

it('names the store in a database error', function () {
    config()->set('database.connections.down', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x']);
    config()->set('vector.stores.pg_down', ['driver' => 'pgvector', 'connection' => 'down']);

    expect(fn () => Vector::store('pg_down')->healthCheck())
        ->toThrow(VectorConnectionException::class, 'Vector store [pg_down] (pgvector) failed');
});

it('lets a programming error inside a pgvector call pass through unchanged', function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    Embeddings::extend('broken', static fn (): EmbeddingProviderContract => new class implements EmbeddingProviderContract
    {
        public function embed(string $text): array
        {
            throw new LogicException('bug in the provider');
        }

        public function embedBatch(array $texts): array
        {
            throw new LogicException('bug in the provider');
        }

        public function dimensions(): int
        {
            return 2;
        }

        public function model(): string
        {
            return 'broken';
        }
    });
    config()->set('vector.embeddings.providers.broken', ['driver' => 'broken']);

    $collection = 'fix_'.uniqid();
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition($collection, dimensions: 2, embeddings: 'broken'));

    expect(fn () => $store->upsert($collection, [Document::make('a', 'text')]))
        ->toThrow(LogicException::class, 'bug in the provider');
});
