<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Testing;

use AgenticGraph\Vector\Builder\Builder;
use AgenticGraph\Vector\Contracts\AliasStoreContract;
use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\Contracts\MigrationStoreContract;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\FieldType;
use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Events\VectorIngestCompleted;
use AgenticGraph\Vector\Events\VectorIngestFailed;
use AgenticGraph\Vector\Events\VectorIngestProgressed;
use AgenticGraph\Vector\Events\VectorMigrationProgressed;
use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException;
use AgenticGraph\Vector\Exceptions\VectorDuplicateDocumentException;
use AgenticGraph\Vector\Exceptions\VectorEmbeddingException;
use AgenticGraph\Vector\Exceptions\VectorInvalidFilterException;
use AgenticGraph\Vector\Exceptions\VectorInvalidMetadataException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Ingest\IngestBatchJob;
use AgenticGraph\Vector\Ingest\QueuedIngest;
use AgenticGraph\Vector\Ingest\TextSplitter;
use AgenticGraph\Vector\Migration\CollectionMigrator;
use AgenticGraph\Vector\Providers\DeterministicEmbeddingProvider;
use AgenticGraph\Vector\Stores\InstrumentedStore;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\FilterCondition;
use AgenticGraph\Vector\ValueObjects\MigrationState;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The behavior every store must share. Use it in a Laravel or Testbench test case and return the
 * store under test from makeStore(), for example `return Vector::store('memory');`. Requires PHPUnit
 * (or Pest, which runs these methods).
 *
 * The suite registers a deterministic, call-counting embedding driver (`conformance`, 8 dimensions)
 * and makes it the default for the test, plus a second one (`conformance_b`, another model) for
 * migrations. Collections it creates, their aliases and migrated copies are dropped after each test.
 * A test of a capability the store does not declare (StoreCapability) is skipped; makeSecondStore() is optional. Queued ingest tests
 * run on the sync connection (inline, any store) and create the `vector_ingest_runs` table when it is missing.
 */
trait StoreConformanceTests
{
    private ?VectorStoreContract $conformanceStore = null;

    private ?CountingEmbeddingProvider $conformanceEmbeddings = null;

    private ?CountingEmbeddingProvider $conformanceEmbeddingsB = null;

    /** @var list<string> */
    private array $conformanceCollections = [];

    abstract protected function makeStore(): VectorStoreContract;

    /**
     * A second store of the same driver with its own data and another batch_size, for the two-stores test.
     * Without an override that test is skipped.
     */
    protected function makeSecondStore(): VectorStoreContract
    {
        $this->markTestSkipped('makeSecondStore() is not implemented for this store.');
    }

    /**
     * Registered by vectorStore() to run before the application is destroyed: an #[After] method runs after
     * Testbench's tearDown, when the container is gone and a store that needs it (Http, config) cannot clean up.
     */
    public function dropConformanceCollections(): void
    {
        $store = $this->conformanceStore;
        $aliases = $store instanceof AliasStoreContract && $store->capabilities()->supports(StoreCapability::Aliases);

        // Two passes: every alias first, since a collection behind an alias cannot be deleted.
        foreach ([true, false] as $aliasPass) {
            foreach ($this->conformanceCollections as $name) {
                try {
                    if ($aliasPass) {
                        if ($aliases && array_key_exists($name, $store->listAliases())) {
                            $store->deleteAlias($name);
                        }

                        continue;
                    }

                    if ($aliases) {
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
        $this->requireCapabilities(StoreCapability::AtomicBatches);

        $builder = $this->vectorBuilder(dimensions: 8);
        $batchSize = $this->vectorStore()->batchSize();
        $documents = $this->contentDocuments(2 * $batchSize);
        $bad = $batchSize + intdiv($batchSize, 2);
        $documents[$bad] = Document::make("doc-{$bad}", 'invalid metadata document', ['bad' => new \stdClass]);

        $this->assertConformanceThrows(VectorInvalidMetadataException::class, fn () => $builder->upsert($documents));

        $this->assertSame(2, $builder->fetch(['doc-0', 'doc-'.($batchSize - 1)])->count());
        $this->assertSame(0, $builder->fetch(["doc-{$batchSize}", "doc-{$bad}"])->count());
    }

    // Queued ingest (sync connection: the batches run inline)

    public function test_conformance_queued_upsert_writes_every_batch_and_completes_the_run(): void
    {
        $builder = $this->vectorBuilder(dimensions: 8);
        $batchSize = $this->vectorStore()->batchSize();
        $total = 2 * $batchSize + 1;
        $events = $this->recordIngestEvents();
        $documents = $this->contentDocuments($total);
        // A repeated id keeps its last copy, so it is written once, in the last batch.
        $documents[] = Document::make('doc-0', 'last copy of doc-0', ['index' => 0]);

        $run = $this->queuedIngest($builder)->upsert($documents);

        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);
        $this->assertNull($run->batchId);
        $this->assertSame([$total, $total], [$run->totalDocuments, $run->processedDocuments]);
        $this->assertSame([[$batchSize, 'done', 1], [$batchSize, 'done', 1], [1, 'done', 1]], array_map(
            static fn (array $batch): array => [$batch['count'], $batch['status'], $batch['attempts']],
            array_values($run->batches),
        ));
        $this->assertNotNull($run->startedAt);
        $this->assertNotNull($run->finishedAt);
        $this->assertSame(3, $this->countingEmbeddings()->embedBatchCallCount);
        $this->assertSame($total, $builder->fetch(array_map(static fn (int $i): string => "doc-{$i}", range(0, $total - 1)))->count());
        $this->assertSame('last copy of doc-0', $builder->fetch(['doc-0'])->hits[0]->content);
        $this->assertSame([[$batchSize, $total, 0], [2 * $batchSize, $total, 0], [$total, $total, 0]], $events['progressed']);
        $this->assertSame([[$total, $total, 0]], $events['completed']);
        $this->assertSame([], $events['failed']);
        $this->assertEquals($run, QueuedIngest::find($run->id));
    }

    public function test_conformance_a_failed_queued_batch_fails_the_run_and_the_other_batches_are_written(): void
    {
        $this->requireCapabilities(StoreCapability::AtomicBatches);

        $builder = $this->vectorBuilder(dimensions: 8);
        $batchSize = $this->vectorStore()->batchSize();
        $documents = $this->contentDocuments(3 * $batchSize);
        $bad = $batchSize + intdiv($batchSize, 2);
        $documents[$bad] = Document::make("doc-{$bad}", 'invalid metadata document', ['bad' => new \stdClass]);
        $events = $this->recordIngestEvents();

        $run = $this->queuedIngest($builder)->upsert($documents);

        $this->assertNotNull($run);
        $this->assertSame('failed', $run->status);
        $this->assertSame(2 * $batchSize, $run->processedDocuments);
        $this->assertSame(['done', 'failed', 'done'], array_column(array_values($run->batches), 'status'));
        $this->assertSame([1], array_keys($run->failedBatches()));
        $this->assertStringContainsString('Metadata at [bad]', (string) $run->batches[1]['error']);
        // The batch after the failed one ran too; the failed batch wrote nothing.
        $this->assertSame(2, $builder->fetch(['doc-0', 'doc-'.(3 * $batchSize - 1)])->count());
        $this->assertSame(0, $builder->fetch(["doc-{$batchSize}", "doc-{$bad}"])->count());
        $this->assertSame([2 * $batchSize, 3 * $batchSize, 1], $events['progressed'][2]);
        $this->assertSame([[2 * $batchSize, 3 * $batchSize, 1]], $events['failed']);
        $this->assertSame([], $events['completed']);
    }

    public function test_conformance_a_queued_batch_run_again_writes_and_counts_once(): void
    {
        $builder = $this->vectorBuilder(dimensions: 8);
        $batchSize = $this->vectorStore()->batchSize();
        $documents = $this->contentDocuments($batchSize + 1);
        $run = $this->queuedIngest($builder)->upsert($documents);
        $this->assertNotNull($run);

        // A worker killed after the commit: the same job runs again with the same documents.
        $job = new IngestBatchJob($run->store, $this->lastVectorCollection(), [], $run->id, 0, array_slice($documents, 0, $batchSize));
        app()->call([$job, 'handle']);

        $again = QueuedIngest::find($run->id);
        $this->assertNotNull($again);
        $this->assertSame('completed', $again->status);
        $this->assertSame($batchSize + 1, $again->processedDocuments);
        $this->assertSame([$batchSize, 1], array_column(array_values($again->batches), 'count'));
        $this->assertCount($batchSize + 1, array_merge(...$this->allPages($builder, 1000)));
    }

    public function test_conformance_queued_batches_follow_an_alias_switch(): void
    {
        $store = $this->migrationStore();
        $builder = $this->vectorBuilder(dimensions: 8);
        $alias = $this->lastVectorCollection();
        $state = $this->migrator()->migrate($alias, new CollectionDefinition($alias, dimensions: 8, metric: 'cosine'));
        $batchSize = $store->batchSize();
        $switched = false;

        app('events')->listen(VectorIngestProgressed::class, function () use ($store, $alias, $state, &$switched): void {
            if (! $switched) {
                $switched = true;
                $store->pointAlias($alias, $state->source);
            }
        });

        $run = $this->queuedIngest($store->collection($alias))->upsert($this->contentDocuments(2 * $batchSize));

        $this->assertSame('completed', $run?->status);
        $this->assertSame(1, $store->collection($state->target)->fetch(['doc-0', "doc-{$batchSize}"])->count());
        $this->assertSame(['doc-'.$batchSize], $store->collection($state->source)->fetch(['doc-0', "doc-{$batchSize}"])->pluck('id'));
    }

    public function test_conformance_queued_upsert_refuses_progress_callbacks_precomputed_vectors_and_insert(): void
    {
        $builder = $this->vectorBuilder(dimensions: 8);
        $this->queuedIngestTable();

        $this->assertConformanceThrows(
            VectorUnsupportedOperationException::class,
            fn () => $builder->onProgress(static function (): void {})->queue('sync')->upsert($this->contentDocuments(1)),
            'VectorIngestProgressed',
        );
        $this->assertConformanceThrows(
            VectorUnsupportedOperationException::class,
            fn () => $builder->queue('sync')->upsert([Document::make('v', 'has a vector', vector: $this->vectorFor('has a vector'))]),
            'precomputed vector',
        );
        $this->assertConformanceThrows(
            VectorUnsupportedOperationException::class,
            fn () => $builder->queue('sync')->insert($this->contentDocuments(1)),
            'upsert() only',
        );
        $this->assertSame(0, DB::table(QueuedIngest::TABLE)->where('collection', $this->lastVectorCollection())->count());
        $this->assertSame([], $builder->listIds()->ids);
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

    // Hybrid search

    public function test_conformance_websearch_keyword_match_reads_words_phrases_exclusions_and_alternatives(): void
    {
        $this->requireCapabilities(StoreCapability::KeywordWebsearch);

        $builder = $this->keywordFixture();

        $this->assertSame(['a', 'b', 'c', 'e'], $this->keywordMatches($builder, 'print', 'websearch'));
        $this->assertSame(['b', 'c'], $this->keywordMatches($builder, 'PRINT Certification', 'websearch'));
        $this->assertSame(['b'], $this->keywordMatches($builder, '"print certification"', 'websearch'));
        $this->assertSame(['a', 'e'], $this->keywordMatches($builder, 'print -certification', 'websearch'));
        $this->assertSame(['d', 'e'], $this->keywordMatches($builder, 'password or invoice', 'websearch'));
        $this->assertSame(['a', 'd'], $this->keywordMatches($builder, 'password -billing or certificate', 'websearch'));
        $this->assertSame([], $this->keywordMatches($builder, 'unknownword', 'websearch'));
    }

    public function test_conformance_any_keyword_match_finds_documents_with_any_query_word_and_ignores_operators(): void
    {
        $builder = $this->keywordFixture();

        $this->assertSame(['a', 'b', 'c', 'e'], $this->keywordMatches($builder, 'print', 'any'));
        $this->assertSame(['a', 'b', 'c', 'e'], $this->keywordMatches($builder, 'PRINT Certification', 'any'));
        $this->assertSame(['a', 'b', 'c', 'e'], $this->keywordMatches($builder, '"print certification"', 'any'));
        $this->assertSame(['a', 'b', 'c', 'e'], $this->keywordMatches($builder, 'print -certification', 'any'));
        $this->assertSame(['d', 'e'], $this->keywordMatches($builder, 'password or invoice', 'any'));
        $this->assertSame(['a', 'e'], $this->keywordMatches($builder, 'certificate billing', 'any'));
        $this->assertSame([], $this->keywordMatches($builder, 'unknownword', 'any'));
    }

    public function test_conformance_a_natural_language_question_gets_keyword_hits_in_any_mode_only(): void
    {
        $this->requireCapabilities(StoreCapability::KeywordWebsearch);

        $builder = $this->keywordFixture();
        $question = 'How do I reset my forgotten password?';

        // No document holds every word: websearch (AND) matches none, any (the default) matches each one with a query word.
        $this->assertSame([], $this->keywordMatches($builder, $question, 'websearch'));
        $this->assertSame(['d', 'e'], $this->keywordMatches($builder, $question));
        $this->assertSame(['d', 'e'], $this->keywordMatches($builder, $question, 'any'));

        // Loose: d holds two query words (reset, password), e one.
        $scores = [];
        foreach ($builder->usingVector([1.0, 0.0, 0.0])->keywords($question)->hybrid()->take(10)->get()->hits as $hit) {
            $scores[(string) $hit->id] = $hit->scores['keyword'] ?? null;
        }
        $this->assertGreaterThan($scores['e'] ?? 0.0, $scores['d'] ?? 0.0);
    }

    public function test_conformance_keyword_match_mode_is_validated_and_defaults_from_config(): void
    {
        $this->requireCapabilities(StoreCapability::KeywordWebsearch);

        $builder = $this->keywordFixture();

        $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->usingVector([1.0, 0.0, 0.0])->keywords('print')->hybrid(match: 'all')->get(), 'match must be one of: any, websearch');

        config()->set('vector.hybrid.match', 'websearch');

        $this->assertSame([], $this->keywordMatches($builder, 'How do I reset my forgotten password?'));
        $this->assertSame(['d', 'e'], $this->keywordMatches($builder, 'How do I reset my forgotten password?', 'any'));
    }

    public function test_conformance_hybrid_puts_an_exact_phrase_document_in_the_top_results(): void
    {
        $this->requireCapabilities(StoreCapability::KeywordWebsearch);

        $builder = $this->keywordFixture();

        $dense = $builder->usingVector([1.0, 0.0, 0.0])->take(3)->get();
        $hybrid = $builder->usingVector([1.0, 0.0, 0.0])->keywords('"print certification"')->hybrid(match: 'websearch')->take(3)->get();

        $this->assertNotContains('b', $dense->pluck('id'));
        $this->assertSame('b', $hybrid->hits[0]->id);
    }

    public function test_conformance_fused_scores_follow_reciprocal_rank_fusion(): void
    {
        $this->requireCapabilities(StoreCapability::KeywordWebsearch);

        $builder = $this->keywordFixture();

        $hits = $builder->usingVector([1.0, 0.0, 0.0])->keywords('"print certification"')->hybrid(k: 10, match: 'websearch', keywordWeight: 1.0)->take(3)->get()->hits;

        // Dense order a, d, c, b, ...; b is the only keyword match.
        $this->assertSame(['b', 'a', 'd'], array_map(static fn ($hit): string => (string) $hit->id, $hits));
        $this->assertEqualsWithDelta(1 / 11 + 1 / 14, $hits[0]->scores['fused'] ?? null, 1e-9);
        $this->assertEqualsWithDelta(1 / 11, $hits[1]->scores['fused'] ?? null, 1e-9);
        $this->assertEqualsWithDelta(1 / 12, $hits[2]->scores['fused'] ?? null, 1e-9);
        // The keyword score is store-defined: only whether a hit matched, and the order, are compared.
        $this->assertNotNull($hits[0]->scores['keyword'] ?? null);
        $this->assertNull($hits[1]->scores['keyword'] ?? null);
    }

    public function test_conformance_keyword_weight_zero_returns_exactly_the_dense_results(): void
    {
        $this->requireCapabilities(StoreCapability::KeywordWebsearch, StoreCapability::UndeclaredFilters);

        $builder = $this->keywordFixture();
        $searches = [
            'take 3' => fn (Builder $b): Builder => $b->take(3),
            'take 10' => fn (Builder $b): Builder => $b->take(10),
            'filter' => fn (Builder $b): Builder => $b->where('group', 'one')->take(10),
            'min score' => fn (Builder $b): Builder => $b->minScore(0.5)->take(10),
        ];

        foreach ($searches as $name => $search) {
            $dense = array_map(static fn ($hit): array => [$hit->id, $hit->score], $search($builder->usingVector([1.0, 0.0, 0.0]))->get()->hits);

            foreach (['any', 'websearch'] as $match) {
                // "print" matches a, b, c and e: at any weight above 0, b and e would join or move up.
                $hits = $search($builder->usingVector([1.0, 0.0, 0.0]))->keywords('print')->hybrid(match: $match, keywordWeight: 0.0)->get()->hits;

                $this->assertSame($dense, array_map(static fn ($hit): array => [$hit->id, $hit->score], $hits), "{$name}, {$match}");

                foreach ($hits as $rank => $hit) {
                    $this->assertEqualsWithDelta(1 / (61 + $rank), $hit->scores['fused'] ?? null, 1e-12, "{$name}, {$match}, rank {$rank}");
                }
            }
        }
    }

    public function test_conformance_keyword_weight_scales_the_keyword_term_exactly(): void
    {
        $this->requireCapabilities(StoreCapability::KeywordWebsearch);

        $builder = $this->keywordFixture();
        $search = fn (float $weight): array => $builder->usingVector([1.0, 0.0, 0.0])->keywords('"print certification"')->hybrid(k: 10, match: 'websearch', keywordWeight: $weight)->take(4)->get()->hits;

        // Dense order a, d, c, b; b is the only keyword match (keyword rank 1, dense rank 4).
        foreach ([[0.1, ['a', 'd', 'b', 'c']], [0.5, ['b', 'a', 'd', 'c']], [2.0, ['b', 'a', 'd', 'c']]] as [$weight, $order]) {
            $hits = $search($weight);
            $fused = [];
            foreach ($hits as $hit) {
                $fused[(string) $hit->id] = $hit->scores['fused'] ?? null;
            }

            $this->assertSame($order, array_keys($fused), (string) $weight);
            $this->assertEqualsWithDelta(1 / 14 + $weight / 11, $fused['b'], 1e-12, (string) $weight);
            $this->assertEqualsWithDelta(1 / 11, $fused['a'], 1e-12, (string) $weight);
            $this->assertEqualsWithDelta(1 / 12, $fused['d'], 1e-12, (string) $weight);
            $this->assertEqualsWithDelta(1 / 13, $fused['c'], 1e-12, (string) $weight);
        }

        $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->usingVector([1.0, 0.0, 0.0])->keywords('print')->hybrid(keywordWeight: -0.1)->get(), 'keywordWeight must be a finite number of at least 0');

        config()->set('vector.hybrid.keyword_weight', 0.5);
        $this->assertEqualsWithDelta(1 / 14 + 0.5 / 11, $builder->usingVector([1.0, 0.0, 0.0])->keywords('"print certification"')->hybrid(k: 10, match: 'websearch')->take(1)->get()->hits[0]->scores['fused'] ?? null, 1e-12);
    }

    public function test_conformance_hybrid_score_stays_the_vector_similarity(): void
    {
        $builder = $this->keywordFixture();
        $dense = [];

        foreach ($builder->usingVector([1.0, 0.0, 0.0])->take(10)->get()->hits as $hit) {
            $dense[(string) $hit->id] = $hit->score;
        }

        $hybrid = $builder->usingVector([1.0, 0.0, 0.0])->keywords('password')->hybrid()->take(10)->get()->hits;

        $this->assertCount(6, $hybrid);

        foreach ($hybrid as $hit) {
            $this->assertEqualsWithDelta($dense[(string) $hit->id], $hit->score, 1e-6, (string) $hit->id);
            $this->assertEqualsWithDelta($hit->score, $hit->scores['vector'] ?? null, 1e-9, (string) $hit->id);
        }

        $this->assertNull($builder->usingVector([1.0, 0.0, 0.0])->take(1)->get()->hits[0]->scores);
    }

    public function test_conformance_a_query_without_lexemes_gives_the_dense_ranking(): void
    {
        $this->requireCapabilities(StoreCapability::KeywordWebsearch);

        $builder = $this->keywordFixture();
        $dense = $builder->usingVector([1.0, 0.0, 0.0])->take(6)->get()->pluck('id');

        foreach (['any', 'websearch'] as $match) {
            foreach (['', '{}', '!!! ??', '""'] as $keywords) {
                $hits = $builder->usingVector([1.0, 0.0, 0.0])->keywords($keywords)->hybrid(match: $match)->take(6)->get()->hits;

                $this->assertSame($dense, array_map(static fn ($hit): string|int => $hit->id, $hits), "{$match}: {$keywords}");
                $this->assertSame([null], array_values(array_unique(array_map(static fn ($hit): ?float => $hit->scores['keyword'] ?? null, $hits))), "{$match}: {$keywords}");
                $this->assertEqualsWithDelta(1 / 61, $hits[0]->scores['fused'] ?? null, 1e-9, "{$match}: {$keywords}");
            }
        }
    }

    public function test_conformance_hybrid_applies_the_filter_to_both_sides(): void
    {
        $this->requireCapabilities(StoreCapability::UndeclaredFilters);

        $builder = $this->keywordFixture();

        $ids = $builder->where('group', 'one')->usingVector([1.0, 0.0, 0.0])->keywords('print')->hybrid()->take(10)->get()->pluck('id');

        $this->assertSame(['a', 'c', 'e'], $this->sortedIds(array_map(strval(...), $ids)));
    }

    public function test_conformance_min_score_applies_to_keyword_only_hits(): void
    {
        $builder = $this->keywordFixture();

        // e matches the keywords but has vector similarity 0.
        $hybrid = $builder->usingVector([1.0, 0.0, 0.0])->keywords('invoice')->hybrid()->minScore(0.5)->take(10)->get();

        $this->assertSame(['a', 'd', 'c', 'b'], $hybrid->pluck('id'));
        $this->assertContains('e', $builder->usingVector([1.0, 0.0, 0.0])->keywords('invoice')->hybrid()->take(10)->get()->pluck('id'));
    }

    public function test_conformance_hybrid_needs_keywords_and_a_keyword_collection(): void
    {
        $plain = $this->vectorBuilder();
        $plain->upsert([Document::make('a', 'print', vector: [1.0, 0.0, 0.0])]);
        $name = $this->lastVectorCollection();

        if (! $this->vectorStore()->capabilities()->supports(StoreCapability::Keyword)) {
            $this->markTestSkipped('The store does not support keyword search.');
        }

        $this->assertConformanceThrows(VectorQueryException::class, fn () => $plain->usingVector([1.0, 0.0, 0.0])->hybrid()->get(), 'needs keywords(text)');
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $plain->usingVector([1.0, 0.0, 0.0])->keywords('print')->get(), 'needs hybrid()');
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $plain->usingVector([1.0, 0.0, 0.0])->keywords('print')->hybrid()->get(), "Collection [{$name}] has no keyword index");
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $plain->usingVector([1.0, 0.0, 0.0])->keywords('print')->hybrid(depth: 0)->get(), 'at least 1');
    }

    public function test_conformance_similar_to_text_is_the_default_keyword_query(): void
    {
        $this->requireCapabilities(StoreCapability::Keyword);

        $builder = $this->vectorBuilder(dimensions: 8, keyword: true);
        $builder->upsert([
            Document::make('z-match', 'print certification', vector: $this->vectorFor('something else')),
            Document::make('a-other', 'something else', vector: $this->vectorFor('something else')),
        ]);

        $hits = $builder->similarTo('print certification')->hybrid()->take(2)->get()->hits;

        // Same vector for both, so only the keyword match puts z-match first.
        $this->assertSame('z-match', $hits[0]->id);
        $this->assertNotNull($hits[0]->scores['keyword'] ?? null);
        $this->assertSame(1, $this->countingEmbeddings()->embedCallCount);
    }

    public function test_conformance_create_collection_rejects_a_different_keyword_setting(): void
    {
        $this->requireCapabilities(StoreCapability::Keyword);

        $store = $this->vectorStore();
        $plain = $this->vectorCollection();
        $keyword = $this->vectorCollection(keyword: true);

        $store->createCollection(new CollectionDefinition($plain, dimensions: 3, metric: 'cosine', keyword: false));
        $store->createCollection(new CollectionDefinition($keyword, dimensions: 3, metric: 'cosine', keyword: true));
        $store->createCollection(new CollectionDefinition($keyword, dimensions: 3, metric: 'cosine'));

        foreach ([
            new CollectionDefinition($plain, dimensions: 3, metric: 'cosine', keyword: true),
            new CollectionDefinition($keyword, dimensions: 3, metric: 'cosine', keyword: false),
            new CollectionDefinition($keyword, dimensions: 3, metric: 'cosine', keyword: true, languages: ['es' => 'spanish']),
            new CollectionDefinition($keyword, dimensions: 3, metric: 'cosine', keyword: true, languageField: 'locale'),
        ] as $definition) {
            $this->assertConformanceThrows(VectorQueryException::class, fn () => $store->createCollection($definition), 'vector:migrate');
        }

        $this->assertNull($store->describeCollection($plain)['keyword'] ?? null);
        $this->assertSame(
            ['languages' => ['en' => 'english', 'english' => 'english', 'es' => 'spanish', 'spanish' => 'spanish'], 'language_field' => 'lang'],
            $store->describeCollection($keyword)['keyword'] ?? null,
        );
        $this->assertConformanceThrows(
            VectorQueryException::class,
            fn () => $store->createCollection(new CollectionDefinition($this->vectorCollectionName(), dimensions: 3, keyword: true, languages: ["en'" => 'english'])),
            'Invalid language map entry',
        );
    }

    public function test_conformance_migration_adds_keyword_search_and_later_migrations_keep_it(): void
    {
        $this->requireCapabilities(StoreCapability::Keyword, StoreCapability::KeywordWebsearch);

        $store = $this->migrationStore();
        $builder = $this->vectorBuilder(dimensions: 8);
        $name = $this->lastVectorCollection();
        $builder->upsert($this->contentDocuments(3));

        $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->similarTo('document 1')->hybrid()->get(), 'no keyword index');

        $added = $this->migrator()->migrate($name, new CollectionDefinition($name, keyword: true));

        $this->assertSame(['copy', true], [$added->mode, $added->definition['keyword'] ?? null]);
        $this->assertSame('doc-2', $builder->usingVector($this->vectorFor('unrelated'))->keywords('"document 2"')->hybrid(match: 'websearch')->take(1)->get()->hits[0]->id);

        $kept = $this->migrator()->migrate($name, new CollectionDefinition($name, embeddings: 'conformance_b'));

        $this->assertSame(['reembed', 'lang'], [$kept->mode, $kept->definition['language_field'] ?? null]);

        $dropped = $this->migrator()->migrate($name, new CollectionDefinition($name, keyword: false));

        $this->assertArrayNotHasKey('keyword', $dropped->definition);
        $this->assertNull($store->describeCollection($name)['keyword'] ?? null);
    }

    // Chunks and collapse

    public function test_conformance_collapse_keeps_the_best_chunk_per_parent_from_an_over_fetch(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert([
            Document::make('a#0', 'a0', ['parent_id' => 'a'], [1.0, 0.0, 0.0]),
            Document::make('a#1', 'a1', ['parent_id' => 'a'], [0.99, 0.1, 0.0]),
            Document::make('a#2', 'a2', ['parent_id' => 'a'], [0.98, 0.2, 0.0]),
            Document::make('b#0', 'b0', ['parent_id' => 'b'], [0.97, 0.25, 0.0]),
            Document::make('solo', 'solo', [], [0.9, 0.4, 0.0]),
            Document::make('c#0', 'c0', ['parent_id' => 'c'], [0.5, 0.8, 0.0]),
        ]);
        $query = $builder->usingVector([1.0, 0.0, 0.0]);

        $this->assertSame(['a#0', 'a#1'], $query->take(2)->get()->pluck('id'));
        $this->assertSame(['a#0', 'b#0'], $query->take(2)->collapse()->get()->pluck('id'));
        $this->assertSame(['a#0', 'b#0', 'solo'], $query->take(3)->collapse('parent_id')->get()->pluck('id'));
        $this->assertSame(3, $query->take(3)->collapse()->get()->count());

        config()->set('vector.collapse.overfetch', 1);
        $this->assertSame(['a#0'], $query->take(2)->collapse()->get()->pluck('id'));

        config()->set('vector.collapse.overfetch', 0);
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $query->collapse(), 'at least 1');
    }

    public function test_conformance_collapse_runs_after_rerank_on_every_fetched_candidate(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert([
            Document::make('a#0', 'alpha', ['parent_id' => 'a'], [1.0, 0.0, 0.0]),
            Document::make('a#1', 'alpha', ['parent_id' => 'a'], [0.95, 0.3, 0.0]),
            Document::make('b#0', 'bravo', ['parent_id' => 'b'], [0.9, 0.4, 0.0]),
            Document::make('b#1', 'zulu', ['parent_id' => 'b'], [0.8, 0.6, 0.0]),
            Document::make('c#0', 'charlie', ['parent_id' => 'c'], [0.0, 1.0, 0.0]),
        ]);

        $results = $builder->usingVector([1.0, 0.0, 0.0])->take(2)->rerank('deterministic', candidates: 1, query: 'zulu')->collapse()->get();

        $this->assertSame(['b#1', 'a#0'], $results->pluck('id'));
        $this->assertSame(1.0, $results->hits[0]->scores['rerank'] ?? null);
    }

    public function test_conformance_upsert_chunks_replaces_a_parents_chunks_and_keeps_the_others(): void
    {
        $this->requireCapabilities(StoreCapability::DeleteByFilter, StoreCapability::FilterRange, StoreCapability::ListIds, StoreCapability::UndeclaredFilters);

        $builder = $this->vectorBuilder(dimensions: 8);
        $splitter = new TextSplitter(size: 20);
        $paragraphs = static fn (int $count, string $word): string => implode("\n\n", array_map(static fn (int $n): string => "{$word} paragraph {$n}.", range(1, $count)));

        $builder->upsertChunks([
            Document::make('doc', $paragraphs(5, 'Old'), ['title' => 'Guide']),
            Document::make(7, $paragraphs(3, 'Other')),
        ], $splitter, ['source' => 'kb']);

        $this->assertSame(['7#0', '7#1', '7#2', 'doc#0', 'doc#1', 'doc#2', 'doc#3', 'doc#4'], $this->sortedIds($builder->listIds()->ids));

        $builder->upsertChunks([Document::make('doc', $paragraphs(2, 'New'), ['title' => 'Guide v2'])], $splitter, ['source' => 'kb']);

        $this->assertSame(['doc#0', 'doc#1'], $this->sortedIds($builder->where('parent_id', 'doc')->listIds()->ids));
        $this->assertSame(['7#0', '7#1', '7#2'], $this->sortedIds($builder->where('parent_id', '7')->listIds()->ids));

        $chunk = $builder->fetch(['doc#1'])->first();
        $this->assertNotNull($chunk);
        $this->assertSame('New paragraph 2.', $chunk->content);
        $this->assertEquals(['title' => 'Guide v2', 'source' => 'kb', 'parent_id' => 'doc', 'chunk' => 1], $chunk->metadata);

        $builder->upsertChunks([Document::make('doc', " \n ")], $splitter);
        $this->assertSame([], $builder->where('parent_id', 'doc')->listIds()->ids);
    }

    public function test_conformance_upsert_chunks_refuses_queue(): void
    {
        $builder = $this->vectorBuilder(dimensions: 8);

        $this->assertConformanceThrows(
            VectorUnsupportedOperationException::class,
            fn () => $builder->queue('sync')->upsertChunks([Document::make('doc', 'text')], new TextSplitter(size: 100)),
            'cannot queue',
        );
        $this->assertSame([], $builder->listIds()->ids);
    }

    // Delete

    public function test_conformance_deletes_by_ids_and_by_filter(): void
    {
        $this->requireCapabilities(StoreCapability::DeleteByFilter, StoreCapability::FilterNot, StoreCapability::ListIds, StoreCapability::UndeclaredFilters);

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
        $this->requireCapabilities(StoreCapability::UndeclaredFilters);

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
        $this->requireCapabilities(StoreCapability::FilterNot, StoreCapability::UndeclaredFilters);

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
        $this->requireCapabilities(StoreCapability::FilterNot, StoreCapability::FilterRange, StoreCapability::UndeclaredFilters);

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
        $this->requireCapabilities(StoreCapability::FilterNot, StoreCapability::UndeclaredFilters);

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
        $this->requireCapabilities(StoreCapability::FilterContains, StoreCapability::UndeclaredFilters);

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
        $this->requireCapabilities(StoreCapability::FilterRange, StoreCapability::UndeclaredFilters);

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
        $this->requireCapabilities(StoreCapability::FilterRange, StoreCapability::UndeclaredFilters);

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
        $this->requireCapabilities(StoreCapability::ListIds, StoreCapability::FilterNot, StoreCapability::UndeclaredFilters);

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
        $capabilities = $this->vectorStore()->capabilities();
        // Declared, so the filter operations below reach their capability check on every store.
        $name = $this->vectorCollectionName();
        $this->vectorStore()->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'cosine', keyword: $capabilities->supports(StoreCapability::Keyword) ?: null, filterable: ['n' => FieldType::Integer, 'tags' => FieldType::KeywordList]));
        $builder = $this->vectorStore()->collection($name);
        $builder->upsert([Document::make('a', 'a', ['tags' => ['x'], 'n' => 1], [1.0, 0.0, 0.0])]);

        /** @var array<string, Closure(): mixed> $operations */
        $operations = [
            StoreCapability::ListIds->value => fn () => $builder->listIds(),
            StoreCapability::UpdateMetadata->value => fn () => $builder->updateMetadata(['a' => ['n' => 2]]),
            StoreCapability::DeleteByFilter->value => fn () => $builder->where('n', 99)->delete(),
            StoreCapability::FilterNot->value => fn () => $builder->where('n', '!=', 1)->usingVector([1.0, 0.0, 0.0])->get(),
            StoreCapability::FilterContains->value => fn () => $builder->whereContains('tags', 'x')->usingVector([1.0, 0.0, 0.0])->get(),
            StoreCapability::FilterRange->value => fn () => $builder->where('n', '>', 0)->usingVector([1.0, 0.0, 0.0])->get(),
            StoreCapability::Keyword->value => fn () => $builder->usingVector([1.0, 0.0, 0.0])->keywords('a')->hybrid()->get(),
            StoreCapability::Aliases->value => fn () => $this->conformanceAliasCall(),
            StoreCapability::KeywordWebsearch->value => fn () => $builder->usingVector([1.0, 0.0, 0.0])->keywords('a')->hybrid(match: 'websearch')->get(),
            StoreCapability::Migrations->value => fn () => $this->conformanceMigrationCall(),
            StoreCapability::UndeclaredFilters->value => fn () => $builder->where('undeclared', 1)->usingVector([1.0, 0.0, 0.0])->get(),
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
        $this->requireCapabilities(StoreCapability::UndeclaredFilters);

        [$alias, $state] = $this->pointedAlias();
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

    public function test_conformance_point_alias_creates_re_points_and_refuses_a_collection_name(): void
    {
        $store = $this->aliasStore();
        $first = $this->vectorCollection();
        $second = $this->vectorCollection();
        $alias = $this->vectorCollectionName();
        $store->collection($first)->upsert([Document::make('in-first', 'in first', vector: [1.0, 0.0, 0.0])]);

        $store->pointAlias($alias, $first);
        $this->assertSame($first, $store->listAliases()[$alias]);
        $this->assertSame(1, $store->collection($alias)->fetch(['in-first'])->count());

        $store->pointAlias($alias, $second);
        $this->assertSame($second, $store->listAliases()[$alias]);
        $this->assertSame(0, $store->collection($alias)->fetch(['in-first'])->count());

        $this->assertConformanceThrows(VectorQueryException::class, fn () => $store->pointAlias($first, $second), 'is a collection');
        $this->assertArrayNotHasKey($first, $store->listAliases());
        $this->assertSame(1, $store->collection($first)->fetch(['in-first'])->count());
        $this->assertConformanceThrows(VectorCollectionNotFoundException::class, fn () => $store->pointAlias($alias, 'conformance_missing_collection'));
        $this->assertSame($second, $store->listAliases()[$alias]);
    }

    public function test_conformance_alias_semantics_and_namespace(): void
    {
        [$alias, $state] = $this->pointedAlias();
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

    public function test_conformance_delete_alias_removes_its_migration_history(): void
    {
        [$alias] = $this->migratedAlias();
        $store = $this->migrationStore();
        $this->assertSame('switched', $store->migration($alias)?->phase);

        $store->deleteAlias($alias);

        $this->assertNull($store->migration($alias));

        // The name used again, as a plain collection: no stale migration of the old alias.
        $store->createCollection(new CollectionDefinition($alias, dimensions: 3, metric: 'cosine'));
        $this->assertNull($store->migration($alias));
    }

    public function test_conformance_delete_alias_refuses_while_its_latest_migration_is_unfinished(): void
    {
        [$alias, $state] = $this->migratedAlias();
        $store = $this->migrationStore();
        $unfinished = new MigrationState($alias, $state->target, "{$alias}__v3", $state->mode, 'copying', $state->definition);
        $store->saveMigration($unfinished);

        $this->assertConformanceThrows(VectorQueryException::class, fn () => $store->deleteAlias($alias), 'unfinished migration');
        $this->assertSame($state->target, $store->listAliases()[$alias]);
        $this->assertSame('copying', $store->migration($alias)?->phase);

        $store->saveMigration($unfinished->withPhase('failed'));
        $store->deleteAlias($alias);

        $this->assertArrayNotHasKey($alias, $store->listAliases());
        $this->assertNull($store->migration($alias));
    }

    public function test_conformance_migration_switches_reads_and_keeps_the_old_collection(): void
    {
        $phases = [];
        app('events')->listen(VectorMigrationProgressed::class, function (VectorMigrationProgressed $event) use (&$phases): void {
            $phases[] = $event->phase;
        });

        [$alias, $state] = $this->migratedAlias();
        $store = $this->migrationStore();

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
        $store = $this->migrationStore();
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
        $store = $this->migrationStore();
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
        $store = $this->migrationStore();
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
        $store = $this->migrationStore();
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
        $store = $this->migrationStore();
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
            $store = $this->migrationStore();
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
        $store = $this->migrationStore();
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

    // Store-neutral contract (declared fields, ids, scores, sizes, several stores)

    public function test_conformance_declared_fields_follow_the_operator_table(): void
    {
        $this->requireCapabilities(StoreCapability::FilterNot, StoreCapability::FilterContains, StoreCapability::FilterRange);
        $builder = $this->declaredFixture();

        // Keyword
        $this->assertMatches(['a'], $builder->where('kind', 'book'));
        $this->assertMatches(['b', 'none'], $builder->where('kind', '!=', 'book'));
        $this->assertMatches(['a', 'b'], $builder->whereIn('kind', ['book', 'film']));
        $this->assertMatches(['none'], $builder->whereNotIn('kind', ['book', 'film']));
        // Integer
        $this->assertMatches(['b'], $builder->where('count', 5));
        $this->assertMatches(['a', 'none'], $builder->where('count', '!=', 5));
        $this->assertMatches(['a', 'b'], $builder->whereIn('count', [1, 5]));
        $this->assertMatches(['none'], $builder->whereNotIn('count', [1, 5]));
        $this->assertMatches(['b'], $builder->where('count', '>', 1));
        $this->assertMatches(['a', 'b'], $builder->where('count', '>=', 1));
        $this->assertMatches(['a'], $builder->where('count', '<', 5));
        $this->assertMatches(['a', 'b'], $builder->where('count', '<=', 5));
        // Float: equality is numeric
        $this->assertMatches(['a'], $builder->where('price', 3));
        $this->assertMatches(['a'], $builder->where('price', 3.0));
        $this->assertMatches(['b', 'none'], $builder->where('price', '!=', 3));
        $this->assertMatches(['a', 'b'], $builder->whereIn('price', [3, 9.5]));
        $this->assertMatches(['none'], $builder->whereNotIn('price', [3, 9.5]));
        $this->assertMatches(['b'], $builder->where('price', '>', 3));
        $this->assertMatches(['a'], $builder->where('price', '<=', 3.5));
        // Bool
        $this->assertMatches(['a'], $builder->where('active', true));
        $this->assertMatches(['b', 'none'], $builder->where('active', '!=', true));
        // Datetime
        $this->assertMatches(['a'], $builder->where('at', '2026-01-01T00:00:00Z'));
        $this->assertMatches(['b', 'none'], $builder->where('at', '!=', new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'))));
        $this->assertMatches(['b'], $builder->where('at', '>', '2026-02-01T00:00:00Z'));
        $this->assertMatches(['a'], $builder->where('at', '<', new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'))));
        // KeywordList: contains one element
        $this->assertMatches(['a', 'b'], $builder->whereContains('tags', 'y'));
        $this->assertMatches(['a'], $builder->whereContains('tags', 'x'));

        foreach ([
            'keyword gt' => fn () => $builder->where('kind', '>', 1),
            'keyword contains' => fn () => $builder->whereContains('kind', 'book'),
            'integer contains' => fn () => $builder->whereContains('count', 1),
            'float contains' => fn () => $builder->whereContains('price', 3),
            'bool in' => fn () => $builder->whereIn('active', [true]),
            'bool not_in' => fn () => $builder->whereNotIn('active', [true]),
            'bool gt' => fn () => $builder->where('active', '>', 0),
            'datetime in' => fn () => $builder->whereIn('at', ['2026-01-01T00:00:00Z']),
            'datetime contains' => fn () => $builder->whereContains('at', '2026-01-01T00:00:00Z'),
            'keyword_list eq' => fn () => $builder->where('tags', 'x'),
            'keyword_list ne' => fn () => $builder->where('tags', '!=', 'x'),
            'keyword_list in' => fn () => $builder->whereIn('tags', ['x']),
            'keyword_list not_in' => fn () => $builder->whereNotIn('tags', ['x']),
            'keyword_list gt' => fn () => $builder->where('tags', '>', 1),
        ] as $case => $filtered) {
            $this->assertConformanceThrows(VectorInvalidFilterException::class, fn () => $filtered()->usingVector([1.0, 0.0, 0.0])->get(), 'is not allowed on field');
        }
    }

    public function test_conformance_declared_fields_are_validated_on_write(): void
    {
        $builder = $this->declaredFixture();

        foreach ([['kind' => 5], ['count' => '5'], ['count' => 1.5], ['price' => '3'], ['active' => 1], ['at' => '2026-01-01'], ['tags' => 'x'], ['tags' => [1]]] as $metadata) {
            $document = Document::make('bad', 'bad', $metadata, [1.0, 0.0, 0.0]);

            $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->upsert([$document]), 'is declared');
            $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->insert([$document]), 'is declared');

            if ($this->vectorStore()->capabilities()->supports(StoreCapability::UpdateMetadata)) {
                $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->updateMetadata(['a' => $metadata]), 'is declared');
            }
        }

        $this->assertSame(0, $builder->fetch(['bad'])->count());
        $this->assertSame('book', $builder->fetch(['a'])->first()?->metadata['kind'] ?? null);

        // A missing field, null, a DateTimeInterface for a datetime and an integer for a float are accepted.
        $builder->upsert([Document::make('ok', 'ok', ['kind' => null, 'at' => new DateTimeImmutable('2026-05-01 12:00:00', new DateTimeZone('UTC')), 'price' => 4], [1.0, 0.0, 0.0])]);
        $this->assertEquals(['kind' => null, 'at' => '2026-05-01T12:00:00Z', 'price' => 4.0], $builder->fetch(['ok'])->first()?->metadata);
        $this->assertSame(4.0, $builder->fetch(['ok'])->first()?->metadata['price'] ?? null);
    }

    public function test_conformance_a_selective_filter_on_a_declared_field_still_returns_top_k(): void
    {
        $name = $this->vectorCollectionName();
        $this->vectorStore()->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'cosine', filterable: ['kind' => FieldType::Keyword]));
        $builder = $this->vectorStore()->collection($name);
        $builder->upsert(array_map(
            static fn (int $i): Document => $i % 50 === 0
                ? Document::make("rare-{$i}", 'rare', ['kind' => 'rare'], [0.0, $i / 1000, 1.0])
                : Document::make("common-{$i}", 'common', ['kind' => 'common'], [1.0, $i / 1000, 0.0]),
            range(0, 499),
        ));

        $hits = $builder->where('kind', 'rare')->usingVector([1.0, 0.0, 0.0])->take(10)->get()->hits;

        $this->assertCount(10, $hits);
        $this->assertSame([], array_values(array_filter($hits, static fn ($hit): bool => ($hit->metadata['kind'] ?? null) !== 'rare')));
    }

    public function test_conformance_take_returns_n_hits_when_the_collection_holds_n(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert(array_map(
            static fn (int $i): Document => Document::make("doc-{$i}", "document {$i}", vector: [1.0, $i / 1000, ($i % 7) / 7]),
            range(0, 999),
        ));

        foreach ([100, 500, 1000] as $n) {
            $hits = $builder->usingVector([1.0, 0.0, 0.0])->take($n)->get()->hits;

            $this->assertCount($n, $hits, "take({$n})");
            $this->assertCount($n, array_unique(array_map(static fn ($hit): string => (string) $hit->id, $hits)), "take({$n}) ids");
        }
    }

    public function test_conformance_ids_round_trip_as_strings(): void
    {
        $builder = $this->vectorBuilder();
        $ids = [5, 'with space', 'sym!@#$%^&*()_+-=[]{};:\'",.<>/?\\|`~', 'ünïcødé-日本語-🙂', str_repeat('long', 125)];
        $builder->upsert(array_map(static fn (string|int $id): Document => Document::make($id, 'original', vector: [1.0, 0.0, 0.0]), $ids));
        $expected = $this->sortedIds(array_map(strval(...), $ids));

        $fetched = $builder->fetch($ids)->hits;
        $searched = $builder->usingVector([1.0, 0.0, 0.0])->take(10)->get()->hits;

        foreach ([...$fetched, ...$searched] as $hit) {
            $this->assertIsString($hit->id);
        }

        $this->assertSame($expected, $this->sortedIds(array_map(static fn ($hit): string => $hit->id, $fetched)));
        $this->assertSame($expected, $this->sortedIds(array_map(static fn ($hit): string => $hit->id, $searched)));

        // The integer 5 and the string "5" are one document.
        $builder->upsert([Document::make('5', 'replaced', vector: [1.0, 0.0, 0.0])]);
        $this->assertSame('replaced', $builder->fetch([5])->first()?->content);
        $this->assertSame(1, $builder->fetch([5, '5'])->count());

        if ($this->vectorStore()->capabilities()->supports(StoreCapability::ListIds)) {
            $listed = array_merge(...$this->allPages($builder, 2));
            $this->assertSame($expected, $this->sortedIds($listed));
        }
    }

    public function test_conformance_min_score_boundaries_for_each_metric(): void
    {
        foreach ([
            'cosine' => [['a' => [1.0, 0.0, 0.0], 'b' => [0.0, 1.0, 0.0], 'c' => [-1.0, 0.0, 0.0]], ['a' => 1.0, 'b' => 0.0, 'c' => -1.0], [[1.0, ['a']], [0.0, ['a', 'b']], [-1.0, ['a', 'b', 'c']]]],
            'l2' => [['a' => [1.0, 0.0, 0.0], 'b' => [2.0, 0.0, 0.0], 'c' => [4.0, 0.0, 0.0]], ['a' => 1.0, 'b' => 0.5, 'c' => 0.25], [[1.0, ['a']], [0.5, ['a', 'b']], [0.0, ['a', 'b', 'c']], [-1.0, ['a', 'b', 'c']]]],
            'inner_product' => [['a' => [2.0, 0.0, 0.0], 'b' => [1.0, 0.0, 0.0], 'c' => [-1.0, 0.0, 0.0]], ['a' => 2.0, 'b' => 1.0, 'c' => -1.0], [[1.5, ['a']], [1.0, ['a', 'b']], [-1.0, ['a', 'b', 'c']]]],
        ] as $metric => [$vectors, $scores, $thresholds]) {
            $builder = $this->vectorBuilder(metric: $metric);
            $builder->upsert(array_map(static fn (string $id): Document => Document::make($id, $id, vector: $vectors[$id]), array_keys($vectors)));

            foreach ($builder->usingVector([1.0, 0.0, 0.0])->take(10)->get()->hits as $hit) {
                $this->assertEqualsWithDelta($scores[$hit->id], $hit->score, 1e-6, "{$metric} score of {$hit->id}");
            }

            foreach ($thresholds as [$minScore, $expected]) {
                $ids = array_map(strval(...), $builder->usingVector([1.0, 0.0, 0.0])->minScore($minScore)->take(10)->get()->pluck('id'));
                $this->assertSame($expected, $this->sortedIds($ids), "{$metric} minScore {$minScore}");
            }
        }
    }

    public function test_conformance_an_empty_collection_returns_no_hits(): void
    {
        $builder = $this->vectorBuilder(dimensions: 8);

        $this->assertSame(0, $builder->usingVector(array_fill(0, 8, 0.5))->get()->count());
        $this->assertSame(0, $builder->similarTo('anything')->minScore(-1.0)->get()->count());
    }

    public function test_conformance_a_metric_the_store_cannot_serve_is_refused_at_create(): void
    {
        $name = $this->vectorCollectionName();

        $this->assertConformanceThrows(VectorQueryException::class, fn () => $this->vectorStore()->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'hamming')), 'hamming');
        $this->assertFalse($this->vectorStore()->collectionExists($name));
    }

    public function test_conformance_float_and_integer_metadata_keep_their_types(): void
    {
        $name = $this->vectorCollectionName();
        $this->vectorStore()->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'cosine', filterable: ['price' => FieldType::Float]));
        $builder = $this->vectorStore()->collection($name);
        $builder->upsert([Document::make('a', 'a', ['int' => 3, 'float' => 1.5, 'whole' => 3.0, 'negative' => -2, 'list' => [1, 2.5, 4.0], 'price' => 4], [1.0, 0.0, 0.0])]);

        foreach ([$builder->fetch(['a'])->first()?->metadata, $builder->usingVector([1.0, 0.0, 0.0])->get()->first()?->metadata] as $metadata) {
            $this->assertSame(3, $metadata['int'] ?? null);
            $this->assertSame(1.5, $metadata['float'] ?? null);
            $this->assertSame(3.0, $metadata['whole'] ?? null);
            $this->assertSame(-2, $metadata['negative'] ?? null);
            $this->assertSame([1, 2.5, 4.0], $metadata['list'] ?? null);
            // An integer on a declared float field is stored as a float.
            $this->assertSame(4.0, $metadata['price'] ?? null);
        }

        if ($this->vectorStore()->capabilities()->supports(StoreCapability::UpdateMetadata)) {
            $builder->updateMetadata(['a' => ['price' => 7]]);
            $this->assertSame(7.0, $builder->fetch(['a'])->first()?->metadata['price'] ?? null);
        }
    }

    public function test_conformance_a_zero_vector_is_refused_under_cosine_only(): void
    {
        $zero = Document::make('zero', 'zero', vector: [0.0, 0.0, 0.0]);
        $builder = $this->vectorBuilder();
        $builder->upsert([Document::make('a', 'a', vector: [1.0, 0.0, 0.0])]);

        $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->upsert([$zero]), 'zero vector');
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->insert([$zero]), 'zero vector');
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $builder->usingVector([0.0, 0.0, 0.0])->get(), 'zero vector');
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $this->vectorStore()->search(new SearchQuery($this->lastVectorCollection(), [0.0, 0.0, 0.0])), 'zero vector');
        $this->assertSame(0, $builder->fetch(['zero'])->count());

        foreach (['l2', 'inner_product'] as $metric) {
            $other = $this->vectorBuilder(metric: $metric);
            $other->upsert([$zero, Document::make('a', 'a', vector: [1.0, 0.0, 0.0])]);

            $this->assertSame(['a', 'zero'], $this->sortedIds(array_map(strval(...), $other->usingVector([0.0, 0.0, 0.0])->take(10)->get()->pluck('id'))), $metric);
        }
    }

    public function test_conformance_declared_filter_values_must_have_the_declared_type(): void
    {
        $builder = $this->declaredFixture();
        $collection = $this->lastVectorCollection();

        foreach ([
            'keyword eq int' => fn () => $builder->where('kind', 5),
            'integer eq float' => fn () => $builder->where('count', 1.5),
            'integer eq string' => fn () => $builder->where('count', '1'),
            'integer in string' => fn () => $builder->whereIn('count', ['1']),
            'float gt string' => fn () => $builder->where('price', '>', '2026-01-01T00:00:00Z'),
            'bool eq int' => fn () => $builder->where('active', 1),
            'datetime gt number' => fn () => $builder->where('at', '>', 5),
            'keyword_list contains int' => fn () => $builder->whereContains('tags', 1),
        ] as $case => $filtered) {
            $this->assertConformanceThrows(VectorInvalidFilterException::class, fn () => $filtered()->usingVector([1.0, 0.0, 0.0])->get(), 'must be');
        }

        // The checks run in the store that Vector::store() returns, so a direct call gets them too.
        $filter = NormalizedFilter::from([['field' => 'count', 'operator' => 'eq', 'value' => '1']]);
        $this->assertConformanceThrows(VectorInvalidFilterException::class, fn () => $this->vectorStore()->search(new SearchQuery($collection, [1.0, 0.0, 0.0], $filter)), 'must be');
        $filter = NormalizedFilter::from([['field' => 'kind', 'operator' => 'gt', 'value' => 1]]);
        $this->assertConformanceThrows(VectorInvalidFilterException::class, fn () => $this->vectorStore()->search(new SearchQuery($collection, [1.0, 0.0, 0.0], $filter)), 'is not allowed on field');

        if (! $this->vectorStore()->capabilities()->supports(StoreCapability::UndeclaredFilters)) {
            $this->assertConformanceThrows(VectorUnsupportedOperationException::class, fn () => $builder->where('undeclared', 'x')->usingVector([1.0, 0.0, 0.0])->get(), 'not declared');
        }
    }

    public function test_conformance_a_migration_copy_validates_values_against_the_target_fields(): void
    {
        $this->migrationStore();
        $builder = $this->vectorBuilder();
        $name = $this->lastVectorCollection();
        $builder->upsert([
            Document::make('good', 'good', ['count' => 1], [1.0, 0.0, 0.0]),
            Document::make('bad', 'bad', ['count' => 'five'], [0.0, 1.0, 0.0]),
        ]);

        $this->assertConformanceThrows(
            VectorQueryException::class,
            fn () => $this->migrator()->migrate($name, new CollectionDefinition($name, dimensions: 3, metric: 'cosine', filterable: ['count' => FieldType::Integer])),
            'Document [bad]: metadata field [count]',
        );
        // The batch stopped: no switch, and the name still reads the source.
        $this->assertNotSame('switched', $this->migrationStore()->migration($name)?->phase);
        $this->assertSame(1, $this->vectorStore()->collection($name)->fetch(['bad'])->count());
    }

    public function test_conformance_a_language_map_without_keyword_languages_is_accepted_and_not_applied(): void
    {
        $this->requireCapabilities(StoreCapability::Keyword);

        if ($this->vectorStore()->capabilities()->supports(StoreCapability::KeywordLanguages)) {
            $this->markTestSkipped('The store applies the language map (KeywordLanguages).');
        }

        $name = $this->vectorCollectionName();
        $this->vectorStore()->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'cosine', keyword: true, languages: ['fr' => 'french']));
        $builder = $this->vectorStore()->collection($name);
        $builder->upsert([
            Document::make('fr', 'les chats dorment', ['lang' => 'fr'], [1.0, 0.0, 0.0]),
            Document::make('en', 'the cats sleep', ['lang' => 'en'], [0.0, 1.0, 0.0]),
        ]);

        // Words match as written: no French stemming of "chat" to "chats".
        $this->assertSame(['fr'], $this->keywordMatches($builder, 'chats', 'any'));
        $this->assertSame([], $this->keywordMatches($builder, 'chat', 'any'));
    }

    public function test_conformance_a_delete_of_an_unknown_id_does_nothing(): void
    {
        $builder = $this->vectorBuilder();
        $builder->upsert([Document::make('a', 'a', vector: [1.0, 0.0, 0.0])]);

        $builder->delete('nope');
        $builder->delete(['nope', 7]);

        $this->assertSame(['a'], $builder->fetch(['a', 'nope', 7])->pluck('id'));
    }

    public function test_conformance_describe_collection_has_the_contract_shape(): void
    {
        $store = $this->vectorStore();
        $name = $this->vectorCollectionName();
        $store->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'l2', filterable: ['b' => FieldType::Bool, 'a' => 'keyword']));

        $description = $store->describeCollection($name);

        $keys = array_keys($description);
        sort($keys);
        $this->assertSame(['collection', 'dimensions', 'embedding', 'filterable', 'index', 'keyword', 'metric'], $keys);
        $this->assertSame([$name, 3, 'l2'], [$description['collection'], $description['dimensions'], $description['metric']]);
        $this->assertSame(['a' => 'keyword', 'b' => 'bool'], $description['filterable']);
        $this->assertSame([], $store->describeCollection($this->vectorCollection())['filterable']);

        // The same declaration, or none, is idempotent; another one conflicts.
        $store->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'l2', filterable: ['a' => FieldType::Keyword, 'b' => FieldType::Bool]));
        $store->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'l2'));
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $store->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'l2', filterable: ['a' => FieldType::Keyword])), 'different definition');
        $this->assertConformanceThrows(VectorQueryException::class, fn () => $store->createCollection(new CollectionDefinition($this->vectorCollectionName(), dimensions: 3, filterable: ['a' => 'text'])), 'unknown type');
    }

    public function test_conformance_list_collections_excludes_aliases(): void
    {
        [$alias, $state] = $this->migratedAlias();
        $collections = $this->vectorStore()->listCollections();

        $this->assertNotContains($alias, $collections);
        $this->assertContains($state->target, $collections);
        $this->assertTrue($this->vectorStore()->collectionExists($alias));
    }

    public function test_conformance_two_stores_in_one_process_keep_their_data_batch_size_and_trace_name(): void
    {
        $first = $this->vectorStore();
        $second = $this->makeSecondStore();
        $this->assertNotSame($first->batchSize(), $second->batchSize(), 'makeSecondStore() must configure another batch_size.');

        $name = $this->vectorCollectionName();
        $first->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'cosine'));
        $second->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'cosine'));
        $stores = [];
        app('events')->listen(VectorOperationCompleted::class, function (VectorOperationCompleted $event) use (&$stores, $name): void {
            if ($event->collection === $name && $event->operation === 'search') {
                $stores[] = $event->store;
            }
        });

        try {
            $first->collection($name)->upsert([Document::make('first', 'first', vector: [1.0, 0.0, 0.0])]);
            $second->collection($name)->upsert([Document::make('second', 'second', vector: [1.0, 0.0, 0.0])]);

            $this->assertSame(['first'], $first->collection($name)->usingVector([1.0, 0.0, 0.0])->get()->pluck('id'));
            $this->assertSame(['second'], $second->collection($name)->usingVector([1.0, 0.0, 0.0])->get()->pluck('id'));
            $this->assertSame(0, $first->collection($name)->fetch(['second'])->count());

            if ($first instanceof InstrumentedStore && $second instanceof InstrumentedStore) {
                $this->assertNotSame($first->name(), $second->name());
                $this->assertSame([$first->name(), $second->name()], $stores);
            }
        } finally {
            $second->deleteCollection($name);
        }
    }

    public function test_conformance_a_search_after_the_first_makes_one_store_call(): void
    {
        $name = $this->vectorCollectionName();
        $this->vectorStore()->createCollection(new CollectionDefinition($name, dimensions: 8, metric: 'cosine', filterable: ['kind' => FieldType::Keyword]));
        $counting = new CountingStore($this->vectorStore());
        $store = new InstrumentedStore('acceptance', $counting);
        $store->collection($name)->upsert([Document::make('a', 'first document', ['kind' => 'a'])]);
        $search = fn () => $store->collection($name)->similarTo('first')->where('kind', 'a')->take(2)->get();

        $search();
        $counting->calls = [];
        $search();
        $search();
        $search();

        $this->assertSame(['search', 'search', 'search'], $counting->calls);
    }

    public function test_conformance_a_configured_batch_size_drives_the_chunking(): void
    {
        // A store's own batch_size config sets the chunks.
        $second = $this->makeSecondStore();
        $this->vectorStore();
        $name = $this->vectorCollectionName();
        $second->createCollection(new CollectionDefinition($name, dimensions: 8));
        $this->countingEmbeddings()->reset();

        try {
            $second->collection($name)->upsert($this->contentDocuments(4 * $second->batchSize() + 1));

            $this->assertSame(5, $this->countingEmbeddings()->embedBatchCallCount);
        } finally {
            $second->deleteCollection($name);
        }
    }

    public function test_conformance_a_dry_run_of_an_unknown_alias_throws_not_found_and_creates_nothing(): void
    {
        $name = $this->vectorCollectionName();
        $before = $this->vectorStore()->listCollections();

        $this->assertConformanceThrows(VectorCollectionNotFoundException::class, fn () => $this->migrator()->migrate($name, new CollectionDefinition($name, dimensions: 3), dryRun: true));
        $this->assertSame($before, $this->vectorStore()->listCollections());
        $this->assertFalse($this->vectorStore()->collectionExists($name));
    }

    // Helpers

    /**
     * Skip the test unless the store declares every capability.
     */
    protected function requireCapabilities(StoreCapability ...$capabilities): void
    {
        foreach ($capabilities as $capability) {
            if (! $this->vectorStore()->capabilities()->supports($capability)) {
                $this->markTestSkipped("The store does not declare capability [{$capability->value}].");
            }
        }
    }

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

    /**
     * @return MigrationStoreContract&VectorStoreContract
     */
    protected function migrationStore(): MigrationStoreContract
    {
        $store = $this->vectorStore();

        if (! $store instanceof MigrationStoreContract || ! $store->capabilities()->supports(StoreCapability::Migrations)) {
            $this->markTestSkipped('The store does not support migrations.');
        }

        return $store;
    }

    protected function migrator(): CollectionMigrator
    {
        return new CollectionMigrator($this->migrationStore(), 'conformance', app(EmbeddingsManager::class));
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

    /**
     * Two collections of the same three documents (3 dimensions) and an alias on the second, set up by pointAlias.
     * Returns the alias and a state whose source is the first collection and whose target is the second.
     *
     * @return array{0: string, 1: MigrationState}
     */
    private function pointedAlias(): array
    {
        $store = $this->aliasStore();
        $source = $this->vectorCollection();
        $target = $this->vectorCollection();
        $alias = $this->vectorCollectionName();
        $store->collection($source)->upsert($this->vectorDocuments(3));
        $store->collection($target)->upsert($this->vectorDocuments(3));
        $store->pointAlias($alias, $target);

        return [$alias, new MigrationState($alias, $source, $target, 'copy', 'switched', [])];
    }

    private function conformanceAliasCall(): mixed
    {
        $store = $this->vectorStore();

        if (! $store instanceof AliasStoreContract) {
            throw new VectorUnsupportedOperationException('The store does not implement AliasStoreContract.');
        }

        return $store->listAliases();
    }

    private function conformanceMigrationCall(): mixed
    {
        $store = $this->vectorStore();

        if (! $store instanceof MigrationStoreContract) {
            throw new VectorUnsupportedOperationException('The store does not implement MigrationStoreContract.');
        }

        return $store->migration($this->lastVectorCollection());
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
            $this->beforeApplicationDestroyed($this->dropConformanceCollections(...));
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

    private function vectorCollection(int $dimensions = 3, string $metric = 'cosine', bool $keyword = false): string
    {
        $name = $this->vectorCollectionName();
        $this->vectorStore()->createCollection(new CollectionDefinition($name, dimensions: $dimensions, metric: $metric, keyword: $keyword ?: null));

        return $name;
    }

    private function lastVectorCollection(): string
    {
        return $this->conformanceCollections[array_key_last($this->conformanceCollections)];
    }

    private function vectorBuilder(int $dimensions = 3, string $metric = 'cosine', bool $keyword = false): Builder
    {
        $builder = $this->vectorStore()->collection($this->vectorCollection($dimensions, $metric, $keyword));
        $this->countingEmbeddings()->reset();

        return $builder;
    }

    /**
     * Six documents without a language and with plain words. Dense order for [1, 0, 0]: a, d, c, b, then e, f.
     */
    private function keywordFixture(): Builder
    {
        if (! $this->vectorStore()->capabilities()->supports(StoreCapability::Keyword)) {
            $this->markTestSkipped('The store does not support keyword search.');
        }

        $builder = $this->vectorBuilder(keyword: true);
        $builder->upsert([
            Document::make('a', 'print the certificate today', ['group' => 'one'], [1.0, 0.0, 0.0]),
            Document::make('b', 'print certification button', ['group' => 'two'], [0.6, 0.8, 0.0]),
            Document::make('c', 'certification print order', ['group' => 'one'], [0.8, 0.6, 0.0]),
            Document::make('d', 'reset your password', ['group' => 'two'], [0.9, 0.43589, 0.0]),
            Document::make('e', 'billing invoice and password print', ['group' => 'one'], [0.0, 1.0, 0.0]),
            Document::make('f', 'nothing relevant here', ['group' => 'two'], [0.0, 0.0, 1.0]),
        ]);

        return $builder;
    }

    /**
     * The ids that matched the keywords, sorted.
     *
     * @return list<string>
     */
    private function keywordMatches(Builder $builder, string $keywords, ?string $match = null): array
    {
        $hits = $builder->usingVector([1.0, 0.0, 0.0])->keywords($keywords)->hybrid(depth: 100, match: $match)->take(100)->get()->hits;

        return $this->sortedIds(array_values(array_map(
            static fn ($hit): string => (string) $hit->id,
            array_filter($hits, static fn ($hit): bool => ($hit->scores['keyword'] ?? null) !== null),
        )));
    }

    /**
     * Three documents on one vector in a collection that declares one field of each FieldType; `none` has no metadata.
     */
    private function declaredFixture(): Builder
    {
        $name = $this->vectorCollectionName();
        $this->vectorStore()->createCollection(new CollectionDefinition($name, dimensions: 3, metric: 'cosine', filterable: [
            'kind' => FieldType::Keyword,
            'count' => FieldType::Integer,
            'price' => FieldType::Float,
            'active' => FieldType::Bool,
            'at' => FieldType::Datetime,
            'tags' => FieldType::KeywordList,
        ]));
        $builder = $this->vectorStore()->collection($name);
        $builder->upsert([
            Document::make('a', 'a', ['kind' => 'book', 'count' => 1, 'price' => 3.0, 'active' => true, 'at' => '2026-01-01T00:00:00Z', 'tags' => ['x', 'y']], [1.0, 0.0, 0.0]),
            Document::make('b', 'b', ['kind' => 'film', 'count' => 5, 'price' => 9.5, 'active' => false, 'at' => '2026-03-01T00:00:00Z', 'tags' => ['y']], [1.0, 0.0, 0.0]),
            Document::make('none', 'none', [], [1.0, 0.0, 0.0]),
        ]);

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

    private function queuedIngest(Builder $builder): Builder
    {
        $this->queuedIngestTable();

        return $builder->queue('sync');
    }

    private function queuedIngestTable(): void
    {
        if (! Schema::hasTable(QueuedIngest::TABLE)) {
            (require __DIR__.'/../../database/migrations/create_vector_ingest_runs_table.php.stub')->up();
        }
    }

    /**
     * The ingest events of the last collection as [processed, total, failed batches], by event.
     *
     * @return \ArrayObject<string, array<mixed>>
     */
    private function recordIngestEvents(): \ArrayObject
    {
        /** @var \ArrayObject<string, array<mixed>> $events */
        $events = new \ArrayObject(['progressed' => [], 'completed' => [], 'failed' => []]);
        $collection = $this->lastVectorCollection();

        foreach (['progressed' => VectorIngestProgressed::class, 'completed' => VectorIngestCompleted::class, 'failed' => VectorIngestFailed::class] as $key => $class) {
            app('events')->listen($class, function (VectorIngestProgressed|VectorIngestCompleted|VectorIngestFailed $event) use ($events, $key, $collection): void {
                if ($event->collection === $collection) {
                    $events[$key] = [...$events[$key], [$event->processed, $event->total, $event->failedBatches]];
                }
            });
        }

        return $events;
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
