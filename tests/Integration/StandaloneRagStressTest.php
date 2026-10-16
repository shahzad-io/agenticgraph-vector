<?php

use AgenticGraph\Vector\Builder\Builder;
use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\Contracts\SearchStoreContract;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException;
use AgenticGraph\Vector\Exceptions\VectorInvalidMetadataException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\StandaloneRagCorpus;
use AgenticGraph\Vector\Tests\Support\StandaloneStressHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;

/**
 * Stress Test #1: standalone RAG E2E with real pgvector + real Prism embeddings.
 *
 * Requires: VECTOR_PGVECTOR_INTEGRATION, VECTOR_PRISM_INTEGRATION=true, OPENAI_API_KEY.
 */
describe('Standalone RAG Stress Test #1', function (): void {
    beforeEach(function (): void {
        StandaloneStressHelper::guardOrSkip($this);

        $this->countingProvider = StandaloneStressHelper::configureRealPrism($this->app);
        $this->store = Vector::store('pgvector');
        $this->collection = 'stress_rag_'.uniqid();
        $this->dimensions = Embeddings::using('prism')->dimensions();
        $this->model = Embeddings::using('prism')->model();
        $this->standaloneProof = StandaloneStressHelper::agenticGraphStandaloneProof();
        $this->cosineObservations = [];
    });

    afterEach(function (): void {
        try {
            if (isset($this->store, $this->collection) && $this->store->collectionExists($this->collection)) {
                $this->store->deleteCollection($this->collection);
            }
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    });

    it('proves standalone operation with agenticgraph absent', function (): void {
        expect($this->standaloneProof['package_absent'])->toBeTrue()
            ->and($this->standaloneProof['tracer_unbound'])->toBeTrue()
            ->and($this->standaloneProof['tracing_available'])->toBeFalse();
    });

    it('exercises collection lifecycle, health, and capabilities', function (): void {
        $explicit = 'stress_explicit_'.uniqid();
        $defaultDims = 'stress_default_'.uniqid();

        $this->store->createCollection(new CollectionDefinition($explicit, dimensions: $this->dimensions, metric: 'cosine'));
        expect($this->store->collectionExists($explicit))->toBeTrue();

        $this->store->createCollection(new CollectionDefinition($defaultDims));
        expect($this->store->collectionExists($defaultDims))->toBeTrue();

        $listed = $this->store->listCollections();
        expect($listed)->toContain($explicit)
            ->and($listed)->toContain($defaultDims)
            ->and($listed)->not->toContain('pg_catalog');

        $health = $this->store->healthCheck();
        expect($health->healthy)->toBeTrue()
            ->and($health->latencyMs)->toBeGreaterThanOrEqual(0);

        $capabilities = $this->store->capabilities();
        expect($capabilities->supports(StoreCapability::DeleteByFilter))->toBeTrue()
            ->and($capabilities->supports(StoreCapability::Keyword))->toBeTrue()
            ->and($capabilities->supports(StoreCapability::Aliases))->toBeTrue();

        expect($this->store->batchSize())->toBeGreaterThan(0);

        $this->store->deleteCollection($explicit);
        $this->store->deleteCollection($defaultDims);

        expect($this->store->collectionExists($explicit))->toBeFalse()
            ->and($this->store->collectionExists($defaultDims))->toBeFalse();
    });

    it('batch ingests a realistic corpus with LOCK #4 chunking and onProgress', function (): void {
        config()->set('vector.stores.pgvector', array_merge(config('vector.stores.pgvector'), [
            'batch_size' => 50,
        ]));
        config()->set('vector.batch.size', 50);
        StandaloneStressHelper::resetVectorManager($this->app);
        $this->store = Vector::store('pgvector');
        $this->countingProvider->embedBatchCallCount = 0;

        $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: $this->dimensions));

        $corpus = StandaloneRagCorpus::build(fillerCount: 114, precomputedCount: 24);
        expect($corpus['total'])->toBe(120)
            ->and($corpus['precomputed'])->toBe(24)
            ->and($corpus['content_only'])->toBe(96);

        $progress = [];

        Vector::store('pgvector')
            ->collection($this->collection)
            ->onProgress(function (int $done, int $total) use (&$progress): void {
                $progress[] = [$done, $total];
            })
            ->upsert($corpus['documents'], globalMetadata: ['corpus' => 'stress-1']);

        // 120 docs / batch 50 = 3 chunks; embedBatch once per chunk needing embeddings.
        expect($this->countingProvider->embedBatchCallCount)->toBe(3)
            ->and($progress)->toBe([
                [50, 120],
                [100, 120],
                [120, 120],
            ]);

        $count = Vector::store('pgvector')
            ->collection($this->collection)
            ->fetch(array_map(static fn (int $i): string => "filler-{$i}", range(0, 113)))
            ->count();

        expect($count)->toBe(114);

        $this->corpus = $corpus;
    });

    it('retrieves semantically relevant documents with real embeddings', function (): void {
        $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: $this->dimensions));

        $corpus = StandaloneRagCorpus::build();
        Vector::store('pgvector')->collection($this->collection)->upsert($corpus['documents']);

        foreach ($corpus['anchors'] as $query => $expectedId) {
            $results = Vector::store('pgvector')
                ->collection($this->collection)
                ->similarTo($query)
                ->take(5)
                ->get();

            expect($results->count())->toBeGreaterThan(0)
                ->and($results->first()?->id)->toBe($expectedId);

            $this->cosineObservations[] = [
                'query' => $query,
                'top_score' => $results->first()?->score,
                'second_score' => $results->hits[1]->score ?? null,
            ];
        }

        $topScore = $this->cosineObservations[0]['top_score'];
        expect($topScore)->toBeGreaterThanOrEqual(-1.0)
            ->and($topScore)->toBeLessThanOrEqual(1.0);

        $highRelevance = Vector::store('pgvector')
            ->collection($this->collection)
            ->similarTo(array_key_first($corpus['anchors']))
            ->minScore(0.3)
            ->take(10)
            ->get();

        expect($highRelevance->count())->toBeGreaterThan(0);

        $strictMin = Vector::store('pgvector')
            ->collection($this->collection)
            ->similarTo('unrelated quantum physics black hole singularity')
            ->minScore(0.99)
            ->take(10)
            ->get();

        expect($strictMin->count())->toBeLessThanOrEqual(3);

        $precomputedVector = Embeddings::using('prism')->embed('PostgreSQL pgvector extension cosine similarity');
        $fromVector = Vector::store('pgvector')
            ->collection($this->collection)
            ->usingVector($precomputedVector)
            ->take(3)
            ->get();

        expect($fromVector->first()?->id)->toBe('anchor-pgvector-hnsw');
    });

    it('applies type-aware metadata filters on real corpus data', function (): void {
        $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: $this->dimensions));
        Vector::store('pgvector')->collection($this->collection)->upsert(StandaloneRagCorpus::build()['documents']);

        $stringFilter = Vector::store('pgvector')
            ->collection($this->collection)
            ->where('category', 'legal')
            ->similarTo('contract law and commercial agreements')
            ->take(10)
            ->get();

        expect($stringFilter->pluck('metadata'))->each->toHaveKey('category', 'legal');

        $boolFilter = Vector::store('pgvector')
            ->collection($this->collection)
            ->where('featured', true)
            ->similarTo('Laravel queue workers background jobs')
            ->take(10)
            ->get();

        foreach ($boolFilter->hits as $hit) {
            expect($hit->metadata['featured'])->toBeTrue();
        }

        $numericFilter = Vector::store('pgvector')
            ->collection($this->collection)
            ->where('priority', 10)
            ->similarTo('Laravel queue Redis driver')
            ->take(5)
            ->get();

        foreach ($numericFilter->hits as $hit) {
            expect($hit->metadata['priority'])->toBe(10);
        }

        $composed = Vector::store('pgvector')
            ->collection($this->collection)
            ->where('lang', 'en')
            ->whereIn('category', ['laravel', 'database'])
            ->similarTo('PostgreSQL vector search indexing')
            ->take(10)
            ->get();

        foreach ($composed->hits as $hit) {
            expect($hit->metadata['lang'])->toBe('en')
                ->and($hit->metadata['category'])->toBeIn(['laravel', 'database']);
        }
    });

    it('exercises SearchResult ergonomics and metadata round-trip on real hits', function (): void {
        $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: $this->dimensions));
        Vector::store('pgvector')->collection($this->collection)->upsert(StandaloneRagCorpus::build()['documents']);

        $results = Vector::store('pgvector')
            ->collection($this->collection)
            ->similarTo('sourdough bread baking fermentation')
            ->take(5)
            ->get();

        expect($results->count())->toBe(5)
            ->and($results->first())->not->toBeNull()
            ->and($results->toContext())->toContain('Sourdough')
            ->and($results->toArray()['count'])->toBe(5)
            ->and($results->pluck('id'))->toHaveCount(5);

        $firstMeta = $results->first()?->metadata;
        expect($firstMeta)->toHaveKey('category');

        $filtered = $results->filterByScore(0.2);
        expect($filtered->count())->toBeLessThanOrEqual($results->count());

        $avg = $results->averageScore();
        expect($avg)->not->toBeNull()
            ->and($avg)->toBeGreaterThan(0.0);

        $duplicate = Vector::store('pgvector')
            ->collection($this->collection)
            ->similarTo('sourdough bread baking fermentation')
            ->take(3)
            ->get();

        $merged = $results->merge($duplicate);
        expect($merged->count())->toBe(8);

        $unique = $merged->unique('id');
        expect($unique->count())->toBeLessThanOrEqual(5);
    });

    it('fetches documents by id from the real store', function (): void {
        $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: $this->dimensions));
        Vector::store('pgvector')->collection($this->collection)->upsert(StandaloneRagCorpus::build()['documents']);

        $fetched = Vector::store('pgvector')
            ->collection($this->collection)
            ->fetch(['anchor-ml-backprop', 'filler-42']);

        expect($fetched->count())->toBe(2)
            ->and($fetched->pluck('id'))->toContain('anchor-ml-backprop', 'filler-42')
            ->and($fetched->first()?->score)->toBe(1.0);
    });

    it('deletes by id and by filter using capability-gated paths', function (): void {
        $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: $this->dimensions));
        Vector::store('pgvector')->collection($this->collection)->upsert(StandaloneRagCorpus::build()['documents']);

        Vector::store('pgvector')
            ->collection($this->collection)
            ->delete('filler-0');

        expect(Vector::store('pgvector')->collection($this->collection)->fetch(['filler-0'])->count())->toBe(0);

        Vector::store('pgvector')
            ->collection($this->collection)
            ->where('category', 'legal')
            ->delete();

        $remainingLegal = Vector::store('pgvector')
            ->collection($this->collection)
            ->where('category', 'legal')
            ->similarTo('legal contract breach damages')
            ->take(20)
            ->get();

        expect($remainingLegal->count())->toBe(0);

        Vector::store('pgvector')
            ->collection($this->collection)
            ->deleteByFilter(NormalizedFilter::from([
                ['field' => 'lang', 'operator' => FilterOperator::Equals, 'value' => 'fr'],
            ]));

        $french = Vector::store('pgvector')
            ->collection($this->collection)
            ->where('lang', 'fr')
            ->similarTo('French language document')
            ->take(50)
            ->get();

        expect($french->count())->toBe(0);
    });

    it('exercises Embeddings escape hatch and Builder compile/options/store directly', function (): void {
        $provider = Embeddings::using('prism');

        expect($provider)->toBeInstanceOf(EmbeddingProviderContract::class)
            ->and($provider->dimensions())->toBe($this->dimensions)
            ->and($provider->model())->toBe($this->model);

        $single = $provider->embed('direct embed escape hatch probe');
        expect(count($single))->toBe($this->dimensions);

        $batch = $provider->embedBatch(['batch one', 'batch two']);
        expect($batch)->toHaveCount(2)
            ->and(count($batch[0]))->toBe($this->dimensions);

        $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: $this->dimensions));
        Vector::store('pgvector')->collection($this->collection)->upsert([
            Document::make('compile-test', 'compile and options probe document', ['lang' => 'en']),
        ]);

        /** @var Builder $builder */
        $builder = Vector::store('pgvector')->collection($this->collection);
        expect($builder->store())->toBe($this->store);

        $compiled = $builder
            ->where('lang', 'en')
            ->similarTo('compile and options probe')
            ->take(3)
            ->minScore(0.1)
            ->options(['probe' => true])
            ->compile();

        expect($compiled)->toBeInstanceOf(SearchQuery::class)
            ->and($compiled->collection)->toBe($this->collection)
            ->and($compiled->topK)->toBe(3)
            ->and($compiled->options)->toBe(['probe' => true])
            ->and($compiled->filter)->not->toBeNull();
    });

    it('surfaces typed error paths against real infrastructure', function (): void {
        $this->store->createCollection(new CollectionDefinition($this->collection, dimensions: $this->dimensions));

        $wrongVector = array_fill(0, max(1, $this->dimensions - 1), 0.1);

        expect(fn () => $this->store->search(new SearchQuery(
            collection: $this->collection,
            vector: $wrongVector,
            topK: 1,
        )))->toThrow(VectorDimensionMismatchException::class);

        expect(fn () => Vector::store('pgvector')
            ->collection($this->collection)
            ->get())
            ->toThrow(VectorQueryException::class, 'similarTo(text) or usingVector(vector)');

        expect(fn () => $this->store->fetch('nonexistent_collection_xyz', ['id']))
            ->toThrow(VectorCollectionNotFoundException::class);

        expect(fn () => Vector::store('pgvector')
            ->collection($this->collection)
            ->upsert([
                Document::make('bad-meta', 'invalid', metadata: ['closure' => static fn (): string => 'nope']),
            ]))
            ->toThrow(VectorInvalidMetadataException::class);

        expect(fn () => Vector::store('pgvector')
            ->collection($this->collection)
            ->upsert([
                Document::make('bad-object', 'invalid', metadata: ['obj' => new stdClass]),
            ]))
            ->toThrow(VectorInvalidMetadataException::class);

        expect(fn () => Vector::store('pgvector')
            ->collection($this->collection)
            ->delete())
            ->toThrow(VectorQueryException::class);

        $unsupportedStore = new class($this->store) implements SearchStoreContract
        {
            public function __construct(private readonly VectorStoreContract $inner) {}

            public function search(SearchQuery $query): SearchResult
            {
                return $this->inner->search($query);
            }

            public function capabilities(): StoreCapabilities
            {
                return new StoreCapabilities;
            }
        };

        $unsupportedBuilder = new Builder(
            store: $unsupportedStore,
            collection: $this->collection,
            embeddings: app(EmbeddingsManager::class),
        );

        expect(fn () => $unsupportedBuilder
            ->where('category', 'legal')
            ->delete())
            ->toThrow(VectorUnsupportedOperationException::class);
    });
})->group('standalone-rag-stress');
