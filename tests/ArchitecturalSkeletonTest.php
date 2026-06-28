<?php

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\Contracts\SearchStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\Exceptions\VectorException;
use AgenticGraph\Vector\Exceptions\VectorInvalidMetadataException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Providers\PrismEmbeddingProvider;
use AgenticGraph\Vector\Stores\PgVectorStore;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\VectorManager;

it('registers the service provider', function () {
    $providers = collect($this->app->getLoadedProviders())
        ->filter()
        ->keys();

    expect($providers)->toContain(\AgenticGraph\Vector\VectorServiceProvider::class);
});

it('loads the vector config with expected shape', function () {
    $config = config('vector');

    expect($config)->toBeArray()
        ->and($config)->toHaveKeys(['default', 'stores', 'embeddings', 'batch', 'events'])
        ->and($config['stores'])->toHaveKey('pgvector')
        ->and($config['embeddings']['providers'])->toHaveKey('prism');
});

it('can publish the vector config', function () {
    $this->artisan('vendor:publish', [
        '--provider' => \AgenticGraph\Vector\VectorServiceProvider::class,
        '--tag' => 'vector-config',
    ])->assertSuccessful();
});

it('resolves pgvector store and prism embedding provider via facades', function () {
    expect(Vector::store('pgvector'))->toBeInstanceOf(PgVectorStore::class)
        ->and(Embeddings::using('prism'))->toBeInstanceOf(PrismEmbeddingProvider::class);
});

it('declares pgvector capabilities', function () {
    $caps = Vector::store('pgvector')->capabilities();

    expect($caps->supportsDeleteByFilter())->toBeTrue()
        ->and($caps->supportsNamespaces())->toBeFalse()
        ->and($caps->supportsHybridSearch())->toBeFalse()
        ->and($caps->supportsReranking())->toBeFalse()
        ->and($caps->supportsTenantIsolation())->toBeFalse();
});

it('returns a sensible pgvector batch size', function () {
    expect(Vector::store('pgvector')->batchSize())->toBe(100);
});

it('compiles builder intent into SearchQuery and delegates to store search', function () {
    $capturedQuery = null;

    $store = Mockery::mock(SearchStoreContract::class);
    $store->shouldReceive('search')
        ->once()
        ->with(Mockery::on(function (SearchQuery $query) use (&$capturedQuery): bool {
            $capturedQuery = $query;

            return true;
        }))
        ->andReturn(SearchResult::empty());

    $embeddings = Mockery::mock(EmbeddingsManager::class);
    $embeddings->shouldReceive('driver')->andReturn(new class implements EmbeddingProviderContract
    {
        public function embed(string $text): array
        {
            return [0.1, 0.2, 0.3];
        }

        public function embedBatch(array $texts): array
        {
            return [];
        }

        public function dimensions(): int
        {
            return 3;
        }

        public function model(): string
        {
            return 'test';
        }
    });

    $builder = new \AgenticGraph\Vector\Builder\Builder($store, 'x', $embeddings);

    $builder
        ->where('a', 1)
        ->whereIn('b', [1, 2])
        ->similarTo('q')
        ->take(5)
        ->minScore(0.5)
        ->get();

    expect($capturedQuery)->not->toBeNull()
        ->and($capturedQuery->collection)->toBe('x')
        ->and($capturedQuery->vector)->toBe([0.1, 0.2, 0.3])
        ->and($capturedQuery->topK)->toBe(5)
        ->and($capturedQuery->minScore)->toBe(0.5)
        ->and($capturedQuery->filter)->not->toBeNull()
        ->and($capturedQuery->filter->conditions)->toHaveCount(2)
        ->and($capturedQuery->filter->conditions[0]->field)->toBe('a')
        ->and($capturedQuery->filter->conditions[0]->operator)->toBe(FilterOperator::Equals)
        ->and($capturedQuery->filter->conditions[1]->field)->toBe('b')
        ->and($capturedQuery->filter->conditions[1]->operator)->toBe(FilterOperator::In);
});

it('maps similarTo and usingVector to the same SearchQuery vector field', function () {
    $embeddings = Mockery::mock(EmbeddingsManager::class);
    $embeddings->shouldReceive('driver')->andReturn(new class implements EmbeddingProviderContract
    {
        public function embed(string $text): array
        {
            return [0.5, 0.6];
        }

        public function embedBatch(array $texts): array
        {
            return [];
        }

        public function dimensions(): int
        {
            return 2;
        }

        public function model(): string
        {
            return 'test';
        }
    });

    $store = Mockery::mock(SearchStoreContract::class);

    $builder = new \AgenticGraph\Vector\Builder\Builder($store, 'articles', $embeddings);

    $fromText = $builder->similarTo('hello')->compile();
    $fromVector = $builder->usingVector([0.5, 0.6])->compile();

    expect($fromText->vector)->toBe([0.5, 0.6])
        ->and($fromVector->vector)->toBe([0.5, 0.6]);
});

it('keeps the builder immutable across chained calls', function () {
    $store = Mockery::mock(SearchStoreContract::class);
    $embeddings = Mockery::mock(EmbeddingsManager::class);

    $original = new \AgenticGraph\Vector\Builder\Builder($store, 'articles', $embeddings);
    $withWhere = $original->where('status', 'published');
    $modified = $withWhere->take(5);

    expect($modified->compile()->topK)->toBe(5)
        ->and($original->compile()->topK)->toBe(10)
        ->and($original->compile()->filter)->toBeNull()
        ->and($withWhere->compile()->filter)->not->toBeNull();
});

it('implements SearchResult pure methods', function () {
    $left = new SearchResult([
        new SearchHit('1', 0.9, 'alpha', ['lang' => 'en']),
        new SearchHit('2', 0.4, 'beta', ['lang' => 'en']),
    ], 2);

    $right = new SearchResult([
        new SearchHit('2', 0.4, 'beta', ['lang' => 'en']),
        new SearchHit('3', 0.8, 'gamma', ['lang' => 'fr']),
    ], 2);

    expect($left->count())->toBe(2)
        ->and($left->first()?->id)->toBe('1')
        ->and($left->pluck('content'))->toBe(['alpha', 'beta'])
        ->and($left->toContext('|'))->toBe('alpha|beta')
        ->and($left->toArray()['count'])->toBe(2)
        ->and($left->filterByScore(0.5)->count())->toBe(1)
        ->and($left->averageScore())->toBe(0.65)
        ->and($left->merge($right)->count())->toBe(4)
        ->and($left->merge($right)->unique('id')->count())->toBe(3);
});

it('validates metadata types', function () {
    $validator = new MetadataValidator;

    $validator->validate(['lang' => 'en', 'published' => true, 'tags' => ['a', 'b']]);

    expect(fn () => $validator->validate(['bad' => new stdClass]))
        ->toThrow(VectorInvalidMetadataException::class);

    expect(fn () => $validator->validate(['bad' => fn () => null]))
        ->toThrow(VectorInvalidMetadataException::class);
});

it('defines the vector exception hierarchy', function () {
    $exceptions = [
        VectorQueryException::class,
        \AgenticGraph\Vector\Exceptions\VectorConnectionException::class,
        \AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException::class,
        \AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException::class,
        \AgenticGraph\Vector\Exceptions\VectorInvalidFilterException::class,
        \AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException::class,
        \AgenticGraph\Vector\Exceptions\VectorNotImplementedException::class,
        VectorInvalidMetadataException::class,
    ];

    foreach ($exceptions as $exception) {
        expect(is_subclass_of($exception, VectorException::class))->toBeTrue();
    }
});

it('passes through TraceSpan when agenticgraph is absent', function () {
    expect(TraceSpan::isAgenticGraphTracingAvailable())->toBeFalse();

    $query = new SearchQuery('articles', [0.1, 0.2]);
    $executed = false;

    $result = TraceSpan::search($query, function () use (&$executed): string {
        $executed = true;

        return 'ok';
    });

    expect($executed)->toBeTrue()
        ->and($result)->toBe('ok');
});

it('throws a not-yet-implemented exception from stubbed store methods', function () {
    expect(fn () => Vector::store('pgvector')->search(new SearchQuery('articles', [0.1])))
        ->toThrow(
            \AgenticGraph\Vector\Exceptions\VectorNotImplementedException::class,
            'PgVectorStore::search() is not yet implemented.',
        );
});

it('allows registering a custom store via the manager extend seam', function () {
    Vector::extend('memory', fn (): SearchStoreContract => new class implements SearchStoreContract
    {
        public function search(SearchQuery $query): SearchResult
        {
            return SearchResult::empty();
        }

        public function capabilities(): \AgenticGraph\Vector\ValueObjects\StoreCapabilities
        {
            return new \AgenticGraph\Vector\ValueObjects\StoreCapabilities;
        }
    });

    $store = app(VectorManager::class)->driver('memory');

    expect($store->search(new SearchQuery('articles', [0.1])))->toBeInstanceOf(SearchResult::class);
});
