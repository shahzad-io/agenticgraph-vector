<?php

use AgenticGraph\Vector\Contracts\AliasStoreContract;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Events\VectorMigrationProgressed;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Migration\CollectionMigrator;
use AgenticGraph\Vector\Stores\MemoryStore;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\MigrationState;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Tracing\Tracer;

beforeEach(function () {
    config()->set('vector.default', 'memory');
    config()->set('vector.stores.memory', ['driver' => 'memory', 'batch_size' => 2]);
});

function memoryWithDocuments(string $collection, int $count = 5): void
{
    Vector::store('memory')->createCollection(new CollectionDefinition($collection, dimensions: 3));
    Vector::store('memory')->collection($collection)->upsert(array_map(
        fn (int $i): Document => Document::make("d{$i}", "document {$i}", vector: [1.0, (float) $i, 0.0]),
        range(1, $count),
    ));
}

it('resolves named embedding providers by their driver key', function () {
    config()->set('vector.embeddings.providers.large', ['driver' => 'deterministic', 'dimensions' => 4, 'model' => 'large-model']);

    expect(Embeddings::using('large')->dimensions())->toBe(4)
        ->and(Embeddings::using('large')->model())->toBe('large-model')
        ->and(fn () => Embeddings::using('missing'))->toThrow(InvalidArgumentException::class, 'vector.embeddings.providers');
});

it('records the named provider on the collection', function () {
    config()->set('vector.embeddings.providers.large', ['driver' => 'deterministic', 'dimensions' => 4, 'model' => 'large-model']);

    Vector::store('memory')->createCollection(new CollectionDefinition('named', embeddings: 'large'));

    expect(Vector::store('memory')->describeCollection('named'))->toBe([
        'collection' => 'named',
        'dimensions' => 4,
        'metric' => 'cosine',
        'index' => null,
        'embedding' => ['provider' => 'large', 'model' => 'large-model', 'dimensions' => 4],
    ]);
});

it('migrates in one vector.migrate span with progress events', function () {
    memoryWithDocuments('spanned');
    app()->singleton(Tracer::class);
    Event::fake([VectorMigrationProgressed::class]);

    $state = Vector::migrate('spanned', new CollectionDefinition('spanned', dimensions: 3));

    $spans = app(Tracer::class)->spans;
    $events = Event::dispatched(VectorMigrationProgressed::class)->map(fn (array $args): array => [$args[0]->phase, $args[0]->copied, $args[0]->total])->all();

    expect($spans)->toHaveCount(1)
        ->and($spans[0][0])->toBe('vector.migrate')
        ->and($spans[0][1]['vector.collection'])->toBe('spanned')
        ->and($spans[0][1]['vector.store'])->toBe('memory')
        ->and($spans[0][1]['vector.result_count'])->toBe(5)
        ->and($state->copied)->toBe(5)
        ->and($events)->toBe([['copying', 0, 5], ['copying', 2, 5], ['copying', 4, 5], ['catching_up', 5, 5], ['switched', 5, 5]]);
});

it('throws for a store without the Aliases capability', function () {
    Vector::extend('plain', fn ($app, array $config): MemoryStore => new MemoryStore(
        $config,
        $app->make(EmbeddingsManager::class),
        $app->make(MetadataValidator::class),
        new StoreCapabilities(StoreCapability::ListIds),
    ));

    expect(fn () => Vector::migrate('x', new CollectionDefinition('x'), store: 'plain'))->toThrow(VectorUnsupportedOperationException::class)
        ->and(fn () => Vector::store('plain')->listAliases())->toThrow(VectorUnsupportedOperationException::class);
});

it('runs vector:migrate: dry run, migrate, rollback, drop-old', function () {
    memoryWithDocuments('cli');

    $this->artisan('vector:migrate', ['alias' => 'cli', '--dry-run' => true, '--reembed' => true, '--dimensions' => 8])
        ->expectsTable(['source', 'target', 'documents', 'mode', 'embedding calls', 'phase'], [['cli__v1', 'cli__v2', 5, 'reembed', 3, 'planned']])
        ->assertSuccessful();

    expect(Vector::store('memory')->listAliases())->toBe([]);

    $this->artisan('vector:migrate', ['alias' => 'cli', '--dimensions' => 3, '--batch' => 10])
        ->expectsOutputToContain('switched: 5/5')
        ->expectsOutputToContain('[cli] now points at [cli__v2]')
        ->assertSuccessful();

    $this->artisan('vector:migrate', ['alias' => 'cli', '--rollback' => true])->expectsOutputToContain('[cli] points at [cli__v1] again')->assertSuccessful();
    $this->artisan('vector:migrate', ['alias' => 'cli', '--drop-old' => true])->expectsOutputToContain('not switched')->assertFailed();

    $this->artisan('vector:migrate', ['alias' => 'cli', '--dimensions' => 3])->assertSuccessful();
    $this->artisan('vector:migrate', ['alias' => 'cli', '--drop-old' => true])->expectsOutputToContain('Dropped [cli__v1]')->assertSuccessful();

    expect(Vector::store('memory')->listCollections())->toBe(['cli__v2', 'cli__v3'])
        ->and(Vector::store('memory')->collection('cli')->listIds()->ids)->toHaveCount(5);
});

it('passes --index-option values to the definition and refuses --copy with --reembed', function () {
    memoryWithDocuments('opts', 2);

    $this->artisan('vector:migrate', ['alias' => 'opts', '--copy' => true, '--reembed' => true])->expectsOutputToContain('not both')->assertFailed();
    $this->artisan('vector:migrate', ['alias' => 'opts', '--dimensions' => 3, '--index-option' => ['m=8', 'ef_construction=32']])->assertSuccessful();

    expect(Vector::store('memory')->migration('opts')?->definition['index_options'])->toBe(['m' => 8, 'ef_construction' => 32]);
});

it('resolves the alias per ingest chunk, so a switch mid-ingest moves the rest', function (string $store) {
    if ($store === 'pgvector' && ! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    config()->set("vector.stores.{$store}.batch_size", 2);
    $vector = Vector::store($store);
    $name = 'chunked_'.uniqid();
    $vector->createCollection(new CollectionDefinition($name, dimensions: 3));
    $vector->collection($name)->upsert([Document::make('seed', 'seed', vector: [1.0, 0.0, 0.0])]);
    $state = Vector::migrate($name, new CollectionDefinition($name, dimensions: 3), store: $store);

    try {
        $vector->collection($name)
            ->onProgress(function (int $done) use ($vector, $name, $state): void {
                if ($done === 2) {
                    $vector->pointAlias($name, $state->source);
                }
            })
            ->upsert(array_map(fn (int $i): Document => Document::make("n{$i}", "n{$i}", vector: [1.0, (float) $i, 0.0]), range(1, 4)));

        expect($vector->collection($state->target)->listIds()->ids)->toBe(['n1', 'n2', 'seed'])
            ->and($vector->collection($state->source)->listIds()->ids)->toBe(['n3', 'n4', 'seed']);
    } finally {
        $vector->deleteAlias($name);
        $vector->deleteCollection($state->source);
        $vector->deleteCollection($state->target);
    }
})->with(['memory', 'pgvector']);

it('inherits options left out of the definition from the source collection', function (string $store) {
    if ($store === 'pgvector' && ! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }

    config()->set('vector.embeddings.providers.small3', ['driver' => 'deterministic', 'dimensions' => 3, 'model' => 'small3-model']);
    $vector = Vector::store($store);
    $name = 'inherit_'.uniqid();
    $vector->createCollection(new CollectionDefinition($name, metric: 'l2', index: 'hnsw', indexOptions: ['m' => 8], embeddings: 'small3'));
    $vector->collection($name)->upsert([Document::make('a', 'alpha')]);

    try {
        $this->artisan('vector:migrate', ['alias' => $name, '--store' => $store, '--dry-run' => true, '--index-option' => ['ef_construction=40']])
            ->expectsTable(['dimensions', 'metric', 'index', 'index options', 'embeddings'], [[3, 'l2', 'hnsw', 'm=8, ef_construction=40', 'small3']])
            ->assertSuccessful();

        $state = Vector::migrate($name, new CollectionDefinition($name, indexOptions: ['ef_construction' => 40]), store: $store);
        $target = $vector->describeCollection($name);

        expect($state->mode)->toBe('copy')
            ->and($target['collection'])->toBe($state->target)
            ->and($target['dimensions'])->toBe(3)
            ->and($target['metric'])->toBe('l2')
            ->and($target['index'])->toEqual(['type' => 'hnsw', 'options' => ['m' => 8, 'ef_construction' => 40]])
            ->and($target['embedding'])->toBe(['provider' => 'small3', 'model' => 'small3-model', 'dimensions' => 3]);
    } finally {
        if (isset($state)) {
            $vector->deleteAlias($name);
            $vector->deleteCollection($state->source);
            $vector->deleteCollection($state->target);
        } else {
            $vector->deleteCollection($name);
        }
    }
})->with(['memory', 'pgvector']);

it('stops catch-up passes at vector.migration.catch_up_max_passes', function () {
    config()->set('vector.migration.catch_up_max_passes', 3);
    $planned = new MigrationState('a', 'a__v1', 'a__v2', 'copy', 'catching_up', ['dimensions' => 3, 'metric' => 'cosine', 'index' => null, 'index_options' => [], 'embeddings' => null]);

    $store = Mockery::mock(AliasStoreContract::class, VectorStoreContract::class);
    $store->shouldReceive('batchSize')->andReturn(10);
    $store->shouldReceive('describeCollection')->andReturn(['collection' => 'a__v1', 'dimensions' => 3, 'metric' => 'cosine', 'index' => null, 'embedding' => null]);
    $store->shouldReceive('migration')->andReturn($planned);
    $store->shouldReceive('catchUpMigration')->times(3)->andReturn(1000);
    $store->shouldReceive('finishMigration')->once()->andReturn($planned->withPhase('switched'));

    expect((new CollectionMigrator($store, 'mock', app(EmbeddingsManager::class)))->migrate('a', new CollectionDefinition('a', dimensions: 3, metric: 'cosine'))->phase)->toBe('switched');
});

it('prints each progress line once when vector:migrate runs twice in one process', function () {
    memoryWithDocuments('twice', 2);

    Artisan::call('vector:migrate', ['alias' => 'twice', '--dimensions' => 3]);
    Artisan::call('vector:migrate', ['alias' => 'twice', '--dimensions' => 3]);

    expect(substr_count(Artisan::output(), 'switched: 2/2 (twice__v2 -> twice__v3)'))->toBe(1);
});

it('refuses to re-embed into a width the provider does not produce, before writing anything', function () {
    memoryWithDocuments('narrow', 2);

    expect(fn () => Vector::migrate('narrow', new CollectionDefinition('narrow', dimensions: 3), mode: 'reembed'))
        ->toThrow(VectorQueryException::class, 'embeds at 8')
        ->and(Vector::store('memory')->listCollections())->toBe(['narrow'])
        ->and(Vector::store('memory')->listAliases())->toBe([]);
});
