<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Testing;

use AgenticGraph\Vector\Builder\Builder;
use AgenticGraph\Vector\Contracts\AliasStoreContract;
use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Events\VectorMigrationProgressed;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException;
use AgenticGraph\Vector\Exceptions\VectorDuplicateDocumentException;
use AgenticGraph\Vector\Exceptions\VectorEmbeddingException;
use AgenticGraph\Vector\Exceptions\VectorInvalidFilterException;
use AgenticGraph\Vector\Exceptions\VectorInvalidMetadataException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Migration\CollectionMigrator;
use AgenticGraph\Vector\Providers\DeterministicEmbeddingProvider;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\FilterCondition;
use AgenticGraph\Vector\ValueObjects\MigrationState;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\After;
use Throwable;

/**
 * The behavior every store must share. Use it in a Laravel or Testbench test case and return the
 * store under test from makeStore(), for example `return Vector::store('memory');`. Requires PHPUnit
 * (or Pest, which runs these methods).
 *
 * The suite registers a deterministic, call-counting embedding driver (`conformance`, 8 dimensions)
 * and makes it the default for the test, plus a second one (`conformance_b`, another model) for
 * migrations. Collections it creates, their aliases and migrated copies are dropped after each test.
 * Alias and migration tests are skipped for a store without the Aliases capability.
 */
trait StoreConformanceTests
{
    private ?VectorStoreContract $conformanceStore = null;

    private ?CountingEmbeddingProvider $conformanceEmbeddings = null;

    private ?CountingEmbeddingProvider $conformanceEmbeddingsB = null;

    /** @var list<string> */
    private array $conformanceCollections = [];

    abstract protected function makeStore(): VectorStoreContract;

    #[After]
    public function dropConformanceCollections(): void
    {
        $store = $this->conformanceStore;

        foreach ($this->conformanceCollections as $name) {
            try {
                // Aliases first, then their collections (a collection behind an alias cannot be deleted).
                if ($store instanceof AliasStoreContract && $store->capabilities()->supports(StoreCapability::Aliases)) {
                    if (array_key_exists($name, $store->listAliases())) {
                        $store->deleteAlias($name);
                    }

                    foreach ($store->listCollections() as $collection) {
                        if (str_starts_with($collection, "{$name}__v")) {
                            $store->deleteCollection($collection);
                        }
                    }
                }

                if ($store?->collectionExists($name)) {
                    $store->deleteCollection($name);
                }
            } catch (Throwable) {
                // Best-effort cleanup.
            }
        }

        $this->conformanceCollections = [];
        $this->conformanceStore = null;
    }

    // Collections

    public function test_conformance_collection_lifecycle(): void
    {
        $store = $this->vectorStore();
        $name = $this->vectorCollectionName();

        $store->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'cosine'));

        $this->assertTrue($store->collectionExists($name));
        $this->assertContains($name, $store->listCollections());

        $store->deleteCollection($name);

        $this->assertFalse($store->collectionExists($name));
        $this->assertNotContains($name, $store->listCollections());
        $this->assertConformanceThrows(VectorCollectionNotFoundException::class, fn () => $store->deleteCollection($name));
    }

    public function test_conformance_create_collection_is_idempotent_for_the_same_definition(): void
    {
        $store = $this->vectorStore();
        $name = $this->vectorCollectionName();
        $definition = new CollectionDefinition($name, dimensions: 3, metric: 'cosine');

        $store->createCollection($definition);
        $store->createCollection($definition);

        $this->assertTrue($store->collectionExists($name));
    }

    public function test_conformance_create_collection_rejects_a_conflicting_definition(): void
    {
        $store = $this->vectorStore();
        $name = $this->vectorCollection();

        $this->assertConformanceThrows(
            VectorQueryException::class,
            fn () => $store->createCollection(new CollectionDefinition($name, dimensions: 4, metric: 'cosine')),
            'already exists with a different definition',
        );
        $this->assertConformanceThrows(
            VectorQueryException::class,
            fn () => $store->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'l2')),
            'already exists with a different definition',
        );
    }

    public function test_conformance_create_collection_rejects_dimensions_below_one_and_stays_usable(): void
    {
        $store = $this->vectorStore();
        $name = $this->vectorCollectionName();

        $this->assertConformanceThrows(
            VectorQueryException::class,
            fn () => $store->createCollection(new CollectionDefinition($name, dimensions: 0)),
            'dimensions must be at least 1',
        );
        $this->assertFalse($store->collectionExists($name));

        $store->createCollection(new CollectionDefinition($name, dimensions: 3));

        $this->assertTrue($store->collectionExists($name));
    }

    public function test_conformance_collection_dimensions_default_to_the_embedding_provider(): void
    {
        $store = $this->vectorStore();
        $name = $this->vectorCollectionName();

        $store->createCollection(new CollectionDefinition($name, metric: 'cosine'));
        $store->collection($name)->upsert([Document::make('doc', 'dimension alignment check')]);

        $vector = $this->vectorFor('dimension alignment check');

        $this->assertCount(8, $vector);
        $this->assertSame(['doc'], $store->collection($name)->usingVector($vector)->take(1)->get()->pluck('id'));
        $this->assertConformanceThrows(
            VectorDimensionMismatchException::class,
            fn () => $store->collection($name)->usingVector([1.0, 0.0, 0.0])->get(),
        );
    }

    public function test_conformance_unknown_collection_throws_not_found(): void
    {
        $store = $this->vectorStore();
        $name = $this->vectorCollectionName();
        $message = "Collection [{$name}] was not found.";

        $operations = [
            fn () => $store->fetch($name, ['one']),
            fn () => $store->search(new SearchQuery($name, [1.0, 0.0, 0.0])),
            fn () => $store->upsert($name, [Document::make('one', 'one', vector: [1.0, 0.0, 0.0])]),
            fn () => $store->delete($name, 'one'),
            fn () => $store->listIds($name),
            fn () => $store->updateMetadata($name, ['one' => ['a' => 1]]),
        ];

        foreach ($operations as $operation) {
            $this->assertConformanceThrows(VectorCollectionNotFoundException::class, $operation, $message);
        }
    }

    public function test_conformance_health_check(): void
    {
        $health = $this->vectorStore()->healthCheck();

        $this->assertTrue($health->healthy);
        $this->assertGreaterThanOrEqual(0, $health->latencyMs);
        $this->assertGreaterThan(0, $this->vectorStore()->batchSize());
    }

    // Documents

    public function test_conformance_metadata_round_trips_with_its_types(): void
    {
        $builder = $this->vectorBuilder();
        $metadata = [
            'lang' => 'en',
            'count' => 42,
            'ratio' => 1.5,
            'published' => true,
            'missing' => null,
            'tags' => ['a', 'b'],
            'author' => ['name' => 'Ada', 'id' => 7],
        ];

        $builder->insert([Document::make('doc', 'metadata round trip', $metadata, [1.0, 0.0, 0.0])]);

        $hit = $builder->fetch(['doc'])->first();

        $this->assertNotNull($hit);
        $this->assertSame('doc', (string) $hit->id);
        $this->assertSame('metadata round trip', $hit->content);
        $this->assertSame(1.0, $hit->score);
        $this->assertEquals($metadata, $hit->metadata);
        $this->assertSame(42, $hit->metadata['count']);
        $this->assertSame(1.5, $hit->metadata['ratio']);
        $this->assertTrue($hit->metadata['published']);
    }

    public function test_conformance_date_time_metadata_is_stored_as_a_utc_iso_string(): void
    {
        $builder = $this->vectorBuilder();

        $builder->upsert([Document::make('doc', 'dated', [
            'published_at' => new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('Europe/Berlin')),
        ], [1.0, 0.0, 0.0])]);

        $this->assertSame('2026-01-02T02:04:05Z', $builder->fetch(['doc'])->first()?->metadata['published_at']);
    }

    public function test_conformance_upsert_replaces_and_merges_global_metadata(): void
    {
        $builder = $this->vectorBuilder();

        $builder->insert([Document::make('one', 'alpha', ['lang' => 'en'], [1.0, 0.0, 0.0])]);
        $builder->upsert(
            [Document::make('one', 'updated', ['lang' => 'en'], [1.0, 0.0, 0.0])],
            globalMetadata: ['source' => 'test', 'lang' => 'global'],
        );

        $hit = $builder->fetch(['one'])->first();

        $this->assertSame('updated', $hit?->content);
        $this->assertEquals(['lang' => 'en', 'source' => 'test'], $hit->metadata);
    }

    public function test_conformance_upsert_keeps_the_last_copy_of_a_repeated_id(): void
    {
        $vectorBuilder = $this->vectorBuilder();
        $progress = [];

        $vectorBuilder
            ->onProgress(function (int $done, int $total) use (&$progress): void {
                $progress[] = [$done, $total];
            })
            ->upsert([
                Document::make('a', 'first copy', ['copy' => 1], [1.0, 0.0, 0.0]),
                Document::make('b', 'only copy', [], [0.0, 1.0, 0.0]),
                Document::make('a', 'last copy', ['copy' => 3], [0.0, 0.0, 1.0]),
            ]);

        $hit = $vectorBuilder->fetch(['a'])->first();

        $this->assertSame('last copy', $hit?->content);
        $this->assertEquals(['copy' => 3], $hit->metadata);
        $this->assertSame(['a', 'b'], $this->sortedIds($vectorBuilder->listIds()->ids));
        $this->assertSame('a', $vectorBuilder->usingVector([0.0, 0.0, 1.0])->take(1)->get()->first()?->id);
        $this->assertSame([[2, 2]], $progress);
    }

    public function test_conformance_fetch_ignores_unknown_and_empty_ids(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert([Document::make('one', 'alpha', vector: [1.0, 0.0, 0.0])]);

        $this->assertSame(0, $builder->fetch([])->count());
        $this->assertSame(0, $builder->fetch(['nope'])->count());
        $this->assertSame(['one'], $builder->fetch(['one', 'nope'])->pluck('id'));
    }

    public function test_conformance_insert_rejects_duplicate_ids(): void
    {
        $builder = $this->vectorBuilder();
        $builder->insert([Document::make('one', 'alpha', vector: [1.0, 0.0, 0.0])]);

        $this->assertConformanceThrows(
            VectorDuplicateDocumentException::class,
            fn () => $builder->insert([Document::make('one', 'again', vector: [1.0, 0.0, 0.0])]),
            'Document [one] already exists',
        );
        $this->assertConformanceThrows(
            VectorDuplicateDocumentException::class,
            fn () => $builder->insert([
                Document::make('two', 'two', vector: [1.0, 0.0, 0.0]),
                Document::make('two', 'two again', vector: [1.0, 0.0, 0.0]),
            ]),
        );

        $this->assertSame('alpha', $builder->fetch(['one'])->first()?->content);
        $this->assertSame(0, $builder->fetch(['two'])->count());
    }

    public function test_conformance_invalid_metadata_is_rejected(): void
    {
        $builder = $this->vectorBuilder();

        $this->assertConformanceThrows(
            VectorInvalidMetadataException::class,
            fn () => $builder->insert([Document::make('bad', 'invalid', ['bad' => new \stdClass], [1.0, 0.0, 0.0])]),
        );
        $this->assertSame(0, $builder->fetch(['bad'])->count());
    }

    public function test_conformance_writes_reject_vectors_of_the_wrong_dimensions(): void
    {
        $builder = $this->vectorBuilder();

        $this->assertConformanceThrows(
            VectorDimensionMismatchException::class,
            fn () => $builder->upsert([Document::make('one', 'alpha', vector: [1.0, 0.0])]),
            'must be 3, 2 given',
        );
    }

    public function test_conformance_documents_without_vectors_are_embedded_in_one_batch_per_chunk(): void
    {
        $builder = $this->vectorBuilder(dimensions: 8);

        $builder->upsert([
            Document::make('precomputed', 'precomputed vector document', vector: $this->vectorFor('precomputed vector document')),
            Document::make('embedded', 'auto embedded batch document'),
        ]);

        $this->assertSame(1, $this->countingEmbeddings()->embedBatchCallCount);
        $this->assertSame(['precomputed'], $builder->similarTo('precomputed vector document')->take(1)->get()->pluck('id'));
        $this->assertSame(['embedded'], $builder->similarTo('auto embedded batch document')->take(1)->get()->pluck('id'));
    }

    public function test_conformance_writes_are_chunked_by_batch_size_with_progress(): void
    {
        $builder = $this->vectorBuilder(dimensions: 8);
        $batchSize = $this->vectorStore()->batchSize();
        $total = 2 * $batchSize + 1;
        $progress = [];

        $builder
            ->onProgress(function (int $done, int $all) use (&$progress): void {
                $progress[] = [$done, $all];
            })
            ->upsert($this->contentDocuments($total));

        $this->assertSame([[$batchSize, $total], [2 * $batchSize, $total], [$total, $total]], $progress);
        $this->assertSame(3, $this->countingEmbeddings()->embedBatchCallCount);
        $this->assertSame($total, $builder->fetch(array_map(static fn (int $i): string => "doc-{$i}", range(0, $total - 1)))->count());

        $this->countingEmbeddings()->reset();
        $builder->insert($this->contentDocuments($total, 'insert'));

        $this->assertSame(3, $this->countingEmbeddings()->embedBatchCallCount);
    }

    public function test_conformance_a_failed_chunk_writes_nothing_and_earlier_chunks_stay(): void
    {
        $builder = $this->vectorBuilder(dimensions: 8);
        $batchSize = $this->vectorStore()->batchSize();
        $documents = $this->contentDocuments(2 * $batchSize);
        $bad = $batchSize + intdiv($batchSize, 2);
        $documents[$bad] = Document::make("doc-{$bad}", 'invalid metadata document', ['bad' => new \stdClass]);

        $this->assertConformanceThrows(VectorInvalidMetadataException::class, fn () => $builder->upsert($documents));

        $this->assertSame(2, $builder->fetch(['doc-0', 'doc-'.($batchSize - 1)])->count());
        $this->assertSame(0, $builder->fetch(["doc-{$batchSize}", "doc-{$bad}"])->count());
    }

    // Search

    public function test_conformance_search_ranks_by_score_with_top_k_and_min_score(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert([
            Document::make('a', 'alpha', vector: [1.0, 0.0, 0.0]),
            Document::make('b', 'beta', vector: [0.9, 0.1, 0.0]),
            Document::make('c', 'gamma', vector: [0.0, 1.0, 0.0]),
        ]);

        $results = $builder->usingVector([1.0, 0.0, 0.0])->take(2)->minScore(0.9)->get();

        $this->assertSame(['a', 'b'], $results->pluck('id'));
        $this->assertGreaterThan($results->hits[1]->score, $results->hits[0]->score);
        $this->assertSame(0, $builder->usingVector([1.0, 0.0, 0.0])->minScore(1.01)->get()->count());
    }

    public function test_conformance_scores_follow_the_collection_metric(): void
    {
        $cosine = $this->vectorBuilder(metric: 'cosine');
        $cosine->upsert([Document::make('doc', 'doc', vector: [0.6, 0.8, 0.0])]);
        $this->assertEqualsWithDelta(0.6, $cosine->usingVector([1.0, 0.0, 0.0])->get()->first()?->score, 1e-5);

        $l2 = $this->vectorBuilder(metric: 'l2');
        $l2->upsert([Document::make('doc', 'doc', vector: [0.0, 1.0, 0.0])]);
        $this->assertEqualsWithDelta(1 / (1 + sqrt(2)), $l2->usingVector([1.0, 0.0, 0.0])->get()->first()?->score, 1e-5);

        $innerProduct = $this->vectorBuilder(metric: 'inner_product');
        $innerProduct->upsert([Document::make('doc', 'doc', vector: [1.0, 2.0, 3.0])]);
        $this->assertEqualsWithDelta(2.0, $innerProduct->usingVector([2.0, 0.0, 0.0])->get()->first()?->score, 1e-5);
        $this->assertSame(1, $innerProduct->usingVector([2.0, 0.0, 0.0])->minScore(1.9)->get()->count());
        $this->assertSame(0, $innerProduct->usingVector([2.0, 0.0, 0.0])->minScore(2.5)->get()->count());
    }

    public function test_conformance_search_requires_a_query(): void
    {
        $this->assertConformanceThrows(
            VectorQueryException::class,
            fn () => $this->vectorBuilder()->where('lang', 'en')->get(),
            'A query is required: call similarTo(text) or usingVector(vector) before get().',
        );
    }

    public function test_conformance_similar_to_embeds_the_query_and_using_vector_does_not(): void
    {
        $builder = $this->vectorBuilder(dimensions: 8);
        $builder->upsert([
            Document::make('match', 'target query', vector: $this->vectorFor('target query')),
            Document::make('other', 'something else', vector: $this->vectorFor('something else')),
        ]);

        $this->assertSame('match', $builder->similarTo('target query')->take(1)->get()->first()?->id);
        $this->assertSame(1, $this->countingEmbeddings()->embedCallCount);

        $this->assertSame('match', $builder->usingVector($this->vectorFor('target query'))->take(1)->get()->first()?->id);
        $this->assertSame(1, $this->countingEmbeddings()->embedCallCount);
    }

    // Delete

    public function test_conformance_deletes_by_ids_and_by_filter(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert([
            Document::make('keep', 'keep', ['status' => 'active'], [1.0, 0.0, 0.0]),
            Document::make('one', 'one', ['status' => 'active'], [1.0, 0.0, 0.0]),
            Document::make('two', 'two', ['status' => 'active'], [1.0, 0.0, 0.0]),
            Document::make('archived', 'archived', ['status' => 'archived'], [1.0, 0.0, 0.0]),
            Document::make('draft', 'draft', ['status' => 'draft'], [1.0, 0.0, 0.0]),
            Document::make('old', 'old', ['status' => 'old'], [1.0, 0.0, 0.0]),
            Document::make('untagged', 'untagged', [], [1.0, 0.0, 0.0]),
        ]);

        $builder->delete('one');
        $builder->delete(['two', 'not-there']);
        $builder->where('status', 'archived')->delete();
        $builder->delete(filter: ['status' => 'draft']);
        $builder->deleteByFilter(new NormalizedFilter([new FilterCondition('status', FilterOperator::Equals, 'old')]));

        $this->assertSame(['keep', 'untagged'], $this->sortedIds($builder->listIds()->ids));

        $builder->where('status', '!=', 'active')->delete();

        $this->assertSame(['keep'], $builder->listIds()->ids);
    }

    public function test_conformance_delete_rejects_ids_together_with_a_filter(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert([Document::make('one', 'one', ['status' => 'archived'], [1.0, 0.0, 0.0])]);

        $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->where('status', 'archived')->delete('one'), 'not both');
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->delete('one', ['status' => 'archived']), 'not both');
        $this->assertSame(1, $builder->fetch(['one'])->count());
    }

    public function test_conformance_delete_by_filter_requires_a_condition(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert([Document::make('one', 'one', [], [1.0, 0.0, 0.0])]);

        $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->delete());
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->deleteByFilter(new NormalizedFilter([])));
        $this->assertConformanceThrows(
            VectorQueryException::class,
            fn () => $this->vectorStore()->deleteByFilter($this->lastVectorCollection(), new NormalizedFilter([])),
        );
        $this->assertSame(1, $builder->fetch(['one'])->count());
    }

    // Filters

    public function test_conformance_equals_and_in_compare_by_type(): void
    {
        $builder = $this->filterFixture([
            'en' => ['lang' => 'en', 'published' => true, 'count' => 5],
            'fr' => ['lang' => 'fr', 'published' => false, 'count' => 7],
            'de' => ['lang' => 'de', 'published' => true, 'count' => 9.5],
        ]);

        $this->assertMatches(['en'], $builder->where('lang', 'en'));
        $this->assertMatches(['de', 'en'], $builder->where('published', true));
        $this->assertMatches(['en'], $builder->where('count', 5));
        $this->assertMatches(['de'], $builder->where('count', '=', 9.5));
        $this->assertMatches(['en', 'fr'], $builder->whereIn('count', [5, 7]));
        $this->assertMatches(['de', 'en'], $builder->whereIn('lang', ['en', 'de']));
        $this->assertMatches(['en'], $builder->where('lang', 'en')->where('published', true)->whereIn('count', [5, 9.5]));
    }

    public function test_conformance_equality_is_type_exact_for_strings_and_numbers(): void
    {
        $vectorBuilder = $this->filterFixture([
            'number' => ['code' => 5, 'flag' => true],
            'string' => ['code' => '5', 'flag' => 'true'],
        ]);

        $this->assertMatches(['string'], $vectorBuilder->where('code', '5'));
        $this->assertMatches(['number'], $vectorBuilder->where('code', 5));
        $this->assertMatches(['string'], $vectorBuilder->whereIn('code', ['5', '6']));
        $this->assertMatches(['number'], $vectorBuilder->where('code', '!=', '5'));
        $this->assertMatches(['string'], $vectorBuilder->whereNotIn('code', [5]));
        $this->assertMatches(['string'], $vectorBuilder->where('flag', 'true'));
        $this->assertMatches(['number'], $vectorBuilder->where('flag', true));
    }

    public function test_conformance_typed_filters_never_error_on_fields_of_another_type(): void
    {
        $builder = $this->filterFixture([
            'number' => ['count' => 5, 'flag' => true],
            'string' => ['count' => 'five', 'flag' => 'yes'],
            'bool' => ['count' => true, 'flag' => 1],
            'list' => ['count' => [5], 'flag' => [true]],
            'none' => [],
        ]);

        $this->assertMatches(['number'], $builder->where('count', 5));
        $this->assertMatches(['number'], $builder->whereIn('count', [5, 6]));
        $this->assertMatches(['number'], $builder->where('flag', true));
        $this->assertMatches(['number'], $builder->where('count', '>', 1));
        $this->assertMatches(['bool', 'list', 'none', 'string'], $builder->where('count', '!=', 5));
    }

    public function test_conformance_not_equals_and_not_in_match_documents_without_the_field(): void
    {
        $builder = $this->filterFixture([
            'en' => ['lang' => 'en', 'count' => 1],
            'fr' => ['lang' => 'fr', 'count' => 2],
            'none' => [],
        ]);

        $this->assertMatches(['fr', 'none'], $builder->where('lang', '!=', 'en'));
        $this->assertMatches(['fr', 'none'], $builder->where('lang', 'ne', 'en'));
        $this->assertMatches(['none'], $builder->whereNotIn('lang', ['en', 'fr']));
        $this->assertMatches(['en', 'none'], $builder->where('count', '<>', 2));
        $this->assertMatches(['fr', 'none'], $builder->whereNotIn('count', [1]));
    }

    public function test_conformance_contains_matches_array_fields_only(): void
    {
        $builder = $this->filterFixture([
            'list' => ['tags' => ['x', 'y'], 'numbers' => [1, 2]],
            'scalar' => ['tags' => 'x', 'numbers' => 2],
            'other' => ['tags' => ['y'], 'numbers' => [3]],
            'none' => [],
        ]);

        $this->assertMatches(['list'], $builder->whereContains('tags', 'x'));
        $this->assertMatches(['list', 'other'], $builder->whereContains('tags', 'y'));
        $this->assertMatches(['list'], $builder->whereContains('numbers', 2));
        $this->assertMatches([], $builder->whereContains('numbers', '2'));
        $this->assertMatches(['list'], $builder->where('tags', 'contains', 'x'));
    }

    public function test_conformance_numeric_ranges_are_type_guarded(): void
    {
        $builder = $this->filterFixture([
            'one' => ['score' => 1],
            'mid' => ['score' => 2.5],
            'ten' => ['score' => 10],
            'text' => ['score' => '7'],
            'none' => [],
        ]);

        $this->assertMatches(['mid', 'ten'], $builder->where('score', '>', 2));
        $this->assertMatches(['mid', 'ten'], $builder->where('score', '>=', 2.5));
        $this->assertMatches(['one'], $builder->where('score', '<', 2.5));
        $this->assertMatches(['mid', 'one', 'ten'], $builder->where('score', 'lte', 10));
        $this->assertMatches(['mid'], $builder->where('score', 'gt', 1)->where('score', 'lt', 10));
    }

    public function test_conformance_date_ranges_compare_utc_iso_strings(): void
    {
        $builder = $this->filterFixture([
            'january' => ['published_at' => '2026-01-15T10:00:00Z'],
            'february' => ['published_at' => new DateTimeImmutable('2026-02-28 23:30:00', new DateTimeZone('UTC'))],
            'march' => ['published_at' => new DateTimeImmutable('2026-03-01 01:00:00', new DateTimeZone('Europe/Berlin'))],
            'june' => ['published_at' => '2026-06-01T00:00:00Z'],
            'number' => ['published_at' => 20260301],
            'none' => [],
        ]);

        $this->assertMatches(
            ['february', 'june', 'march'],
            $builder->where('published_at', '>=', new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('+02:00'))),
        );
        $this->assertMatches(['february', 'january'], $builder->where('published_at', '<', '2026-03-01T00:00:00Z'));
        $this->assertMatches(['march'], $builder->where('published_at', '>=', '2026-03-01T00:00:00Z')->where('published_at', '<', '2026-04-01T00:00:00Z'));
    }

    public function test_conformance_filters_reject_invalid_input(): void
    {
        $builder = $this->vectorBuilder();
        $invalid = [
            fn () => $builder->where('published_at', '>', '2026-01-01'),
            fn () => $builder->where('flag', '>', true),
            fn () => $builder->where('author.name', 'Ada'),
            fn () => $builder->where('lang', ['en']),
            fn () => $builder->whereIn('lang', []),
            fn () => $builder->whereIn('lang', ['en', 1]),
            fn () => $builder->whereContains('tags', ['x']),
            fn () => $builder->where('lang', 'like', 'en'),
        ];

        foreach ($invalid as $filter) {
            $this->assertConformanceThrows(VectorInvalidFilterException::class, $filter);
        }
    }

    // listIds

    public function test_conformance_list_ids_pages_through_every_id_once(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert($this->vectorDocuments(25));

        $pages = $this->allPages($builder, 10);

        $this->assertSame([10, 10, 5], array_map('count', $pages));
        $this->assertSame($this->sortedIds(array_map(static fn (int $i): string => "doc-{$i}", range(0, 24))), $this->sortedIds(array_merge(...$pages)));

        $builder->delete(array_map(static fn (int $i): string => "doc-{$i}", range(20, 24)));

        $this->assertSame([10, 10], array_map('count', $this->allPages($builder, 10)));
        $this->assertSame([], $this->vectorBuilder()->listIds()->ids);
        $this->assertNull($this->vectorBuilder()->listIds()->nextCursor);
    }

    public function test_conformance_list_ids_applies_the_filter(): void
    {
        $builder = $this->filterFixture([
            'en-1' => ['lang' => 'en'],
            'fr-1' => ['lang' => 'fr'],
            'en-2' => ['lang' => 'en'],
            'none' => [],
        ]);

        $this->assertSame(['en-1', 'en-2'], $this->sortedIds(array_merge(...$this->allPages($builder->where('lang', 'en'), 1))));
        $this->assertSame(['fr-1', 'none'], $this->sortedIds($builder->where('lang', '!=', 'en')->listIds()->ids));
    }

    public function test_conformance_list_ids_rejects_a_limit_below_one(): void
    {
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $this->vectorBuilder()->listIds(limit: 0));
    }

    // updateMetadata

    public function test_conformance_update_metadata_merges_or_replaces_without_re_embedding(): void
    {
        $builder = $this->vectorBuilder(dimensions: 8);
        $builder->upsert([
            Document::make('a', 'first document', ['lang' => 'en', 'last_updated' => '2026-01-01']),
            Document::make('b', 'second document', ['lang' => 'fr', 'last_updated' => '2026-01-01']),
        ]);
        $this->countingEmbeddings()->reset();

        $result = $builder->updateMetadata([
            'a' => ['last_updated' => new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'))],
            'b' => ['last_updated' => '2026-03-01'],
            'missing' => ['last_updated' => '2026-03-01'],
        ]);

        $this->assertSame(['a', 'b'], $this->sortedIds($result->updated));
        $this->assertSame(['missing'], $result->unknown);
        $this->assertEquals(['lang' => 'en', 'last_updated' => '2026-02-01T00:00:00Z'], $builder->fetch(['a'])->first()?->metadata);
        $this->assertEquals(['lang' => 'fr', 'last_updated' => '2026-03-01'], $builder->fetch(['b'])->first()?->metadata);

        $replaced = $builder->updateMetadata(['a' => ['only' => 'this']], merge: false);

        $this->assertSame(['a'], $replaced->updated);
        $this->assertEquals(['only' => 'this'], $builder->fetch(['a'])->first()?->metadata);
        $this->assertSame('first document', $builder->fetch(['a'])->first()?->content);
        $this->assertSame(0, $this->countingEmbeddings()->embedCallCount + $this->countingEmbeddings()->embedBatchCallCount);

        $hit = $builder->usingVector($this->vectorFor('first document'))->take(1)->get()->first();

        $this->assertSame('a', (string) $hit?->id);
        $this->assertEqualsWithDelta(1.0, $hit?->score, 1e-5);
    }

    public function test_conformance_update_metadata_validates_values(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert([Document::make('a', 'a', ['lang' => 'en'], [1.0, 0.0, 0.0])]);

        $this->assertConformanceThrows(
            VectorInvalidMetadataException::class,
            fn () => $builder->updateMetadata(['a' => ['bad' => new \stdClass]]),
        );
        $this->assertEquals(['lang' => 'en'], $builder->fetch(['a'])->first()?->metadata);
        $this->assertSame([], $builder->updateMetadata([])->updated);
    }

    // Capabilities

    public function test_conformance_builder_gates_every_unsupported_capability(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert([Document::make('a', 'a', ['tags' => ['x'], 'n' => 1], [1.0, 0.0, 0.0])]);
        $capabilities = $this->vectorStore()->capabilities();

        /** @var array<string, Closure(): mixed> $operations */
        $operations = [
            StoreCapability::ListIds->value => fn () => $builder->listIds(),
            StoreCapability::UpdateMetadata->value => fn () => $builder->updateMetadata(['a' => ['n' => 2]]),
            StoreCapability::DeleteByFilter->value => fn () => $builder->where('n', 99)->delete(),
            StoreCapability::FilterNot->value => fn () => $builder->where('n', '!=', 1)->usingVector([1.0, 0.0, 0.0])->get(),
            StoreCapability::FilterContains->value => fn () => $builder->whereContains('tags', 'x')->usingVector([1.0, 0.0, 0.0])->get(),
            StoreCapability::FilterRange->value => fn () => $builder->where('n', '>', 0)->usingVector([1.0, 0.0, 0.0])->get(),
            StoreCapability::Aliases->value => fn () => $this->conformanceAliasCall(),
        ];

        foreach ($operations as $capability => $operation) {
            if ($capabilities->supports(StoreCapability::from($capability))) {
                $operation();
                $this->addToAssertionCount(1);
            } else {
                $this->assertConformanceThrows(VectorUnsupportedOperationException::class, $operation);
            }
        }
    }

    // Aliases and migrations

    public function test_conformance_aliases_resolve_for_every_operation(): void
    {
        [$alias, $state] = $this->migratedAlias();
        $store = $this->aliasStore();
        $builder = $store->collection($alias);

        $builder->insert([
            Document::make('new', 'new document', ['n' => 2], [0.0, 1.0, 0.0]),
            Document::make('temp', 'temporary document', ['n' => 1], [0.0, 0.0, 1.0]),
        ]);
        $builder->upsert([Document::make('doc-0', 'changed document', ['index' => 0], [1.0, 0.0, 0.0])]);
        $builder->updateMetadata(['doc-1' => ['index' => 10]]);
        $builder->delete('doc-2');
        $builder->where('n', 1)->delete();

        $target = $store->collection($state->target);
        $this->assertSame(['doc-0', 'doc-1', 'new'], $this->sortedIds(array_map(strval(...), $target->fetch(['doc-0', 'doc-1', 'doc-2', 'new', 'temp'])->pluck('id'))));
        $this->assertSame('changed document', $target->fetch(['doc-0'])->hits[0]->content);
        $this->assertSame(10, $target->fetch(['doc-1'])->hits[0]->metadata['index']);
        $this->assertSame(['doc-0', 'doc-1', 'doc-2'], $this->sortedIds(array_map(strval(...), $store->collection($state->source)->fetch(['doc-0', 'doc-1', 'doc-2', 'new'])->pluck('id'))));

        $this->assertSame(['doc-0', 'doc-1', 'new'], $this->allPages($builder, 10)[0]);
        $this->assertSame('new', $builder->usingVector([0.0, 1.0, 0.0])->take(1)->get()->hits[0]->id);
        $this->assertSame('new', $store->search(new SearchQuery($alias, [0.0, 1.0, 0.0], topK: 1))->hits[0]->id);
        $this->assertSame(['doc-1'], array_map(strval(...), $store->fetch($alias, ['doc-1'])->pluck('id')));
    }

    public function test_conformance_alias_semantics_and_namespace(): void
    {
        [$alias, $state] = $this->migratedAlias();
        $store = $this->aliasStore();

        $this->assertTrue($store->collectionExists($alias));
        $this->assertNotContains($alias, $store->listCollections());
        $this->assertContains($state->source, $store->listCollections());
        $this->assertContains($state->target, $store->listCollections());
        $this->assertSame($state->target, $store->listAliases()[$alias]);
        $this->assertSame($state->target, $store->describeCollection($alias)['collection']);

        $store->createCollection(new CollectionDefinition($alias, dimensions: 3, metric: 'cosine'));
        $this->assertConformanceThrows(
            VectorQueryException::class,
            fn () => $store->createCollection(new CollectionDefinition($alias, dimensions: 4, metric: 'cosine')),
            'migrate',
        );
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $store->deleteCollection($alias), 'is an alias');
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $store->deleteCollection($state->target), "is the target of alias [{$alias}]");

        $store->deleteAlias($alias);

        $this->assertFalse($store->collectionExists($alias));
        $this->assertTrue($store->collectionExists($state->target));
        $this->assertConformanceThrows(VectorCollectionNotFoundException::class, fn () => $store->deleteAlias($alias));

        // The name is free again, as a new and empty collection.
        $store->createCollection(new CollectionDefinition($alias, dimensions: 3, metric: 'cosine'));
        $this->assertSame([], $store->collection($alias)->listIds()->ids);
        $this->assertCount(3, $store->collection($state->target)->listIds()->ids);
    }

    public function test_conformance_migration_switches_reads_and_keeps_the_old_collection(): void
    {
        $phases = [];
        app('events')->listen(VectorMigrationProgressed::class, function (VectorMigrationProgressed $event) use (&$phases): void {
            $phases[] = $event->phase;
        });

        [$alias, $state] = $this->migratedAlias();
        $store = $this->aliasStore();

        $this->assertSame('switched', $state->phase);
        $this->assertSame("{$alias}__v1", $state->source);
        $this->assertSame("{$alias}__v2", $state->target);
        $this->assertSame(3, $state->copied);
        $this->assertSame('copying', $phases[0]);
        $this->assertSame('switched', $phases[array_key_last($phases)]);

        $store->collection($state->source)->upsert([Document::make('only-old', 'only in the old collection', [], [1.0, 0.0, 0.0])]);

        $this->assertSame([], $store->collection($alias)->fetch(['only-old'])->hits);
        $this->assertCount(4, $store->collection($state->source)->listIds()->ids);
    }

    public function test_conformance_adoption_keeps_the_name_working(): void
    {
        $store = $this->aliasStore();
        $builder = $this->vectorBuilder();
        $name = $this->lastVectorCollection();
        $builder->upsert($this->vectorDocuments(3));

        $this->migrator()->migrate($name, new CollectionDefinition($name, dimensions: 3, metric: 'cosine'));

        $this->assertSame(['doc-0', 'doc-1', 'doc-2'], $this->allPages($builder, 10)[0]);
        $this->assertSame('doc-1', $builder->usingVector([1.0, 1.0, 0.0])->take(1)->get()->hits[0]->id);
        $this->assertNotContains($name, $store->listCollections());
    }

    public function test_conformance_migration_copies_matching_embeddings_and_re_embeds_otherwise(): void
    {
        $store = $this->aliasStore();
        $builder = $this->vectorBuilder(dimensions: 8);
        $name = $this->lastVectorCollection();
        $builder->upsert($this->contentDocuments(3));
        $this->countingEmbeddings()->reset();

        $copied = $this->migrator()->migrate($name, new CollectionDefinition($name, dimensions: 8));

        $this->assertSame('copy', $copied->mode);
        $this->assertSame(0, $this->countingEmbeddings()->embedBatchCallCount);

        $providerB = $this->conformanceEmbeddingsB ?? throw new \LogicException('The conformance store is not set up.');
        $reembedded = $this->migrator()->migrate($name, new CollectionDefinition($name, embeddings: 'conformance_b'));

        $this->assertSame('reembed', $reembedded->mode);
        $this->assertSame(1, $providerB->embedBatchCallCount);
        $this->assertSame(0, $this->countingEmbeddings()->embedBatchCallCount);
        $this->assertSame(['provider' => 'conformance_b', 'model' => 'deterministic-b', 'dimensions' => 8], $store->describeCollection($name)['embedding']);

        // Queries embed with the recorded provider of the collection the alias points to.
        $this->assertSame('doc-1', $builder->similarTo('batch ingestion document 1')->take(1)->get()->hits[0]->id);
        $this->assertSame(1, $providerB->embedCallCount);
        $this->assertSame(0, $this->countingEmbeddings()->embedCallCount);
    }

    public function test_conformance_migration_refuses_an_unknown_source_embedding(): void
    {
        $store = $this->aliasStore();
        $name = $this->vectorCollectionName();

        app(EmbeddingsManager::class)->extend('conformance_unconfigured', fn (): EmbeddingProviderContract => new class implements EmbeddingProviderContract
        {
            public function embed(string $text): array
            {
                throw new VectorEmbeddingException('No embedding model configured.');
            }

            public function embedBatch(array $texts): array
            {
                throw new VectorEmbeddingException('No embedding model configured.');
            }

            public function dimensions(): int
            {
                return 3;
            }

            public function model(): string
            {
                throw new VectorEmbeddingException('No embedding model configured.');
            }
        });
        config()->set('vector.embeddings.default', 'conformance_unconfigured');
        $store->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'cosine'));
        config()->set('vector.embeddings.default', 'conformance');
        $store->collection($name)->upsert($this->vectorDocuments(2));

        $this->assertNull($store->describeCollection($name)['embedding']);
        $this->assertConformanceThrows(
            VectorQueryException::class,
            fn () => $this->migrator()->migrate($name, new CollectionDefinition($name, dimensions: 3, metric: 'cosine')),
            'no recorded embedding model',
        );
        $this->assertArrayNotHasKey($name, $store->listAliases());

        $state = $this->migrator()->migrate($name, new CollectionDefinition($name, dimensions: 3, metric: 'cosine'), mode: 'copy');

        $this->assertSame('switched', $state->phase);
        $this->assertCount(2, $store->collection($name)->listIds()->ids);
    }

    public function test_conformance_migration_dry_run_writes_nothing(): void
    {
        $store = $this->aliasStore();
        $builder = $this->vectorBuilder(dimensions: 8);
        $name = $this->lastVectorCollection();
        $builder->upsert($this->contentDocuments(5));
        $collections = $store->listCollections();
        $aliases = $store->listAliases();

        $copy = $this->migrator()->migrate($name, new CollectionDefinition($name, dimensions: 8), dryRun: true);
        $reembed = $this->migrator()->migrate($name, new CollectionDefinition($name, embeddings: 'conformance_b'), dryRun: true, batchSize: 2);

        $this->assertSame(['planned', "{$name}__v1", "{$name}__v2", 5, 'copy', 0], [$copy->phase, $copy->source, $copy->target, $copy->total, $copy->mode, $copy->estimatedEmbeddingCalls(2)]);
        $this->assertSame(['reembed', 3], [$reembed->mode, $reembed->estimatedEmbeddingCalls(2)]);
        $this->assertSame($collections, $store->listCollections());
        $this->assertSame($aliases, $store->listAliases());
        $this->assertNull($store->migration($name));
    }

    public function test_conformance_a_failed_migration_resumes_without_duplicates(): void
    {
        $store = $this->aliasStore();
        $builder = $this->vectorBuilder(dimensions: 8);
        $name = $this->lastVectorCollection();
        $builder->upsert($this->contentDocuments(5));
        $providerB = $this->conformanceEmbeddingsB ?? throw new \LogicException('The conformance store is not set up.');
        $definition = new CollectionDefinition($name, embeddings: 'conformance_b');
        $providerB->failOnBatch = 2;

        $this->assertConformanceThrows(VectorEmbeddingException::class, fn () => $this->migrator()->migrate($name, $definition, batchSize: 2));

        $failed = $store->migration($name) ?? throw new \LogicException('No migration was recorded.');
        $this->assertSame(['copying', 2], [$failed->phase, $failed->copied]);
        $this->assertConformanceThrows(
            VectorQueryException::class,
            fn () => $this->migrator()->migrate($name, new CollectionDefinition($name, dimensions: 8), batchSize: 2),
            'unfinished with a different definition',
        );

        $providerB->failOnBatch = null;
        $providerB->reset();
        $state = $this->migrator()->migrate($name, $definition, batchSize: 2);

        $this->assertSame(['switched', 5], [$state->phase, $state->copied]);
        $this->assertSame(2, $providerB->embedBatchCallCount);
        $this->assertSame(5, $store->countDocuments($name));
        $this->assertSame(['doc-0', 'doc-1', 'doc-2', 'doc-3', 'doc-4'], array_merge(...$this->allPages($builder, 2)));
    }

    public function test_conformance_writes_during_a_migration_are_not_lost(): void
    {
        foreach (['copy' => new CollectionDefinition('ignored', dimensions: 8), 'reembed' => new CollectionDefinition('ignored', embeddings: 'conformance_b')] as $mode => $definition) {
            $store = $this->aliasStore();
            $builder = $this->vectorBuilder(dimensions: 8);
            $name = $this->lastVectorCollection();
            $builder->upsert($this->contentDocuments(6));
            $wrote = false;

            app('events')->listen(VectorMigrationProgressed::class, function (VectorMigrationProgressed $event) use ($builder, $name, &$wrote): void {
                if ($wrote || $event->alias !== $name || $event->phase !== 'copying' || $event->copied === 0) {
                    return;
                }

                $wrote = true;
                $builder->upsert([Document::make('doc-0', 'rewritten during the copy', ['index' => 0]), Document::make('zz-new', 'added during the copy')]);
                $builder->updateMetadata(['doc-1' => ['index' => 100]]);
                $builder->delete('doc-2');
            });

            $state = $this->migrator()->migrate($name, $definition, batchSize: 3);

            $this->assertTrue($wrote, $mode);
            $this->assertSame($mode, $state->mode);
            $this->assertSame(['doc-0', 'doc-1', 'doc-3', 'doc-4', 'doc-5', 'zz-new'], array_merge(...$this->allPages($builder, 10)), $mode);
            $this->assertSame('rewritten during the copy', $builder->fetch(['doc-0'])->hits[0]->content, $mode);
            $this->assertSame(100, $builder->fetch(['doc-1'])->hits[0]->metadata['index'], $mode);
            $this->assertSame('doc-0', $builder->similarTo('rewritten during the copy')->take(1)->get()->hits[0]->id, $mode);
            $this->assertEqualsWithDelta(1.0, $builder->similarTo('rewritten during the copy')->take(1)->get()->hits[0]->score, 0.0001, $mode);
        }
    }

    public function test_conformance_rollback_and_drop_old(): void
    {
        [$alias, $state] = $this->migratedAlias();
        $store = $this->aliasStore();
        $store->collection($state->target)->upsert([Document::make('only-new', 'only in the new collection', [], [1.0, 0.0, 0.0])]);

        $rolledBack = $this->migrator()->rollback($alias);

        $this->assertSame('rolled_back', $rolledBack->phase);
        $this->assertSame($state->source, $store->listAliases()[$alias]);
        $this->assertSame([], $store->collection($alias)->fetch(['only-new'])->hits);
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $this->migrator()->dropOld($alias), 'not switched');

        $again = $this->migrator()->migrate($alias, new CollectionDefinition($alias, dimensions: 3, metric: 'cosine'));
        $this->assertSame([$state->source, "{$alias}__v3"], [$again->source, $again->target]);

        $dropped = $this->migrator()->dropOld($alias);

        $this->assertSame('dropped', $dropped->phase);
        $this->assertNotContains($state->source, $store->listCollections());
        $this->assertCount(3, $store->collection($alias)->listIds()->ids);
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $this->migrator()->rollback($alias), 'not switched');
    }

    // Helpers

    /**
     * @return AliasStoreContract&VectorStoreContract
     */
    protected function aliasStore(): AliasStoreContract
    {
        $store = $this->vectorStore();

        if (! $store instanceof AliasStoreContract || ! $store->capabilities()->supports(StoreCapability::Aliases)) {
            $this->markTestSkipped('The store does not support aliases.');
        }

        return $store;
    }

    protected function migrator(): CollectionMigrator
    {
        return new CollectionMigrator($this->aliasStore(), 'conformance', app(EmbeddingsManager::class));
    }

    /**
     * A plain collection of three documents (3 dimensions), migrated by copy into an alias.
     *
     * @return array{0: string, 1: MigrationState}
     */
    private function migratedAlias(): array
    {
        $builder = $this->vectorBuilder();
        $name = $this->lastVectorCollection();
        $builder->upsert($this->vectorDocuments(3));

        return [$name, $this->migrator()->migrate($name, new CollectionDefinition($name, dimensions: 3, metric: 'cosine'))];
    }

    private function conformanceAliasCall(): mixed
    {
        $store = $this->vectorStore();

        if (! $store instanceof AliasStoreContract) {
            throw new VectorUnsupportedOperationException('The store does not implement AliasStoreContract.');
        }

        return $store->listAliases();
    }

    protected function vectorStore(): VectorStoreContract
    {
        if ($this->conformanceStore === null) {
            $provider = new CountingEmbeddingProvider(new DeterministicEmbeddingProvider(dimensions: 8));
            $this->conformanceEmbeddings = $provider;
            $embeddings = app(EmbeddingsManager::class);
            $embeddings->forgetDrivers();
            // Manager::extend() rebinds the closure to the manager, so capture the provider, not $this.
            $embeddings->extend('conformance', fn (): CountingEmbeddingProvider => $provider);
            $providerB = new CountingEmbeddingProvider(new DeterministicEmbeddingProvider(dimensions: 8, modelName: 'deterministic-b'));
            $this->conformanceEmbeddingsB = $providerB;
            $embeddings->extend('conformance_b', fn (): CountingEmbeddingProvider => $providerB);
            config()->set('vector.embeddings.default', 'conformance');

            $this->conformanceStore = $this->makeStore();
        }

        return $this->conformanceStore;
    }

    protected function countingEmbeddings(): CountingEmbeddingProvider
    {
        $this->vectorStore();

        return $this->conformanceEmbeddings ?? throw new \LogicException('The conformance store is not set up.');
    }

    private function vectorCollectionName(): string
    {
        $name = 'conformance_'.bin2hex(random_bytes(6));
        $this->conformanceCollections[] = $name;

        return $name;
    }

    private function vectorCollection(int $dimensions = 3, string $metric = 'cosine'): string
    {
        $name = $this->vectorCollectionName();
        $this->vectorStore()->createCollection(new CollectionDefinition($name, dimensions: $dimensions, metric: $metric));

        return $name;
    }

    private function lastVectorCollection(): string
    {
        return $this->conformanceCollections[array_key_last($this->conformanceCollections)];
    }

    private function vectorBuilder(int $dimensions = 3, string $metric = 'cosine'): Builder
    {
        $builder = $this->vectorStore()->collection($this->vectorCollection($dimensions, $metric));
        $this->countingEmbeddings()->reset();

        return $builder;
    }

    /**
     * One document per id with the given metadata, all on the same vector.
     *
     * @param  array<string, array<string, mixed>>  $metadataById
     */
    private function filterFixture(array $metadataById): Builder
    {
        $builder = $this->vectorBuilder();
        $documents = [];

        foreach ($metadataById as $id => $metadata) {
            $documents[] = Document::make((string) $id, (string) $id, $metadata, [1.0, 0.0, 0.0]);
        }

        $builder->upsert($documents);

        return $builder;
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertMatches(array $expected, Builder $filtered): void
    {
        $ids = array_map(strval(...), $filtered->usingVector([1.0, 0.0, 0.0])->take(100)->get()->pluck('id'));

        $this->assertSame($this->sortedIds($expected), $this->sortedIds($ids));
    }

    /**
     * @return list<list<string>>
     */
    private function allPages(Builder $builder, int $limit): array
    {
        $pages = [];
        $cursor = null;

        do {
            $page = $builder->listIds($cursor, $limit);
            $pages[] = $page->ids;
            $cursor = $page->nextCursor;
        } while ($cursor !== null && count($pages) < 100);

        return $pages;
    }

    /**
     * @return list<Document>
     */
    private function contentDocuments(int $count, string $prefix = 'doc'): array
    {
        return array_map(
            static fn (int $i): Document => Document::make("{$prefix}-{$i}", "batch ingestion document {$i}", ['index' => $i]),
            range(0, $count - 1),
        );
    }

    /**
     * @return list<Document>
     */
    private function vectorDocuments(int $count): array
    {
        return array_map(
            static fn (int $i): Document => Document::make("doc-{$i}", "document {$i}", ['index' => $i], [1.0, (float) $i, 0.0]),
            range(0, $count - 1),
        );
    }

    /**
     * @return list<float>
     */
    private function vectorFor(string $text): array
    {
        return (new DeterministicEmbeddingProvider(dimensions: 8))->embed($text);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function sortedIds(array $ids): array
    {
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * @param  class-string<Throwable>  $exception
     * @param  Closure(): mixed  $operation
     */
    private function assertConformanceThrows(string $exception, Closure $operation, ?string $message = null): void
    {
        try {
            $operation();
        } catch (Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown, 'Unexpected exception: '.$thrown->getMessage());

            if ($message !== null) {
                $this->assertStringContainsString($message, $thrown->getMessage());
            }

            return;
        }

        $this->fail("Expected {$exception} was not thrown.");
    }
}
