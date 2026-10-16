<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores;

use AgenticGraph\Vector\Concerns\InteractsWithBuilder;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\PgVectorMetric;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Exceptions\VectorConnectionException;
use AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException;
use AgenticGraph\Vector\Exceptions\VectorDuplicateDocumentException;
use AgenticGraph\Vector\Exceptions\VectorException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Stores\PgVector\PgVectorCollectionRegistry;
use AgenticGraph\Vector\Stores\PgVector\PgVectorExtensionGuard;
use AgenticGraph\Vector\Stores\PgVector\PgVectorFilterTranslator;
use AgenticGraph\Vector\Stores\PgVector\PgVectorIndex;
use AgenticGraph\Vector\Stores\PgVector\PgVectorSearchOptions;
use AgenticGraph\Vector\Stores\PgVector\PgVectorVectorFormatter;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\HealthCheckResult;
use AgenticGraph\Vector\ValueObjects\IdPage;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;
use AgenticGraph\Vector\ValueObjects\UpdateMetadataResult;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

final class PgVectorStore implements VectorStoreContract
{
    use InteractsWithBuilder;

    private readonly PgVectorCollectionRegistry $registry;

    private readonly PgVectorFilterTranslator $filterTranslator;

    private readonly PgVectorExtensionGuard $extensionGuard;

    /** Extension check and registry table done for this store instance (one per process). */
    private bool $prepared = false;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly DatabaseManager $db,
        private readonly EmbeddingsManager $embeddings,
        private readonly MetadataValidator $metadataValidator,
        ?PgVectorCollectionRegistry $registry = null,
        ?PgVectorFilterTranslator $filterTranslator = null,
        ?PgVectorExtensionGuard $extensionGuard = null,
    ) {
        $this->registry = $registry ?? new PgVectorCollectionRegistry(
            $this->connection(),
            (string) ($config['registry_table'] ?? 'vector_collection_registry'),
        );
        $this->filterTranslator = $filterTranslator ?? new PgVectorFilterTranslator;
        $this->extensionGuard = $extensionGuard ?? new PgVectorExtensionGuard(
            $this->connection(),
            (bool) ($config['install_extension'] ?? true),
        );
    }

    public function search(SearchQuery $query): SearchResult
    {
        return $this->run(function () use ($query): SearchResult {
            $this->preparePgVector();

            return $this->connection()->transaction(function () use ($query): SearchResult {
                PgVectorSearchOptions::apply($this->connection(), $query->options);

                $collection = $this->registry->resolve($query->collection);
                $this->assertVectorDimensions($query->vector, $collection['dimensions'], $query->collection);

                $metric = $collection['metric'];
                $storage = $this->registry->qualifiedStorageName($collection['storage_identifier']);
                $operator = $metric->distanceOperator();
                $vector = PgVectorVectorFormatter::format($query->vector);
                $filter = $this->filterTranslator->translate($query->filter);

                $distanceExpression = "embedding {$operator} ?::vector";
                $scoreExpression = $metric->scoreSqlExpression($distanceExpression);

                $sql = "SELECT id, content, metadata, {$scoreExpression} AS score FROM {$storage} WHERE {$filter['sql']}";
                $bindings = [$vector, ...$filter['bindings']];

                if ($query->minScore !== null) {
                    $sql .= " AND ({$distanceExpression}) <= ?";
                    $bindings[] = $vector;
                    $bindings[] = $metric->maxDistanceForMinScore($query->minScore);
                }

                $sql .= " ORDER BY {$distanceExpression} LIMIT ?";
                $bindings[] = $vector;
                $bindings[] = $query->topK;

                $rows = $this->connection()->select($sql, $bindings);

                $hits = array_map(
                    fn (object $row): SearchHit => new SearchHit(
                        id: $row->id,
                        score: (float) $row->score,
                        content: (string) $row->content,
                        metadata: $this->decodeMetadata($row->metadata),
                    ),
                    $rows,
                );

                return new SearchResult(hits: $hits, count: count($hits));
            });
        });
    }

    public function capabilities(): StoreCapabilities
    {
        return new StoreCapabilities(
            StoreCapability::ListIds,
            StoreCapability::UpdateMetadata,
            StoreCapability::DeleteByFilter,
            StoreCapability::FilterNot,
            StoreCapability::FilterContains,
            StoreCapability::FilterRange,
        );
    }

    public function fetch(string $collection, array $ids): SearchResult
    {
        return $this->run(function () use ($collection, $ids): SearchResult {
            $this->preparePgVector();

            if ($ids === []) {
                return SearchResult::empty();
            }

            $resolved = $this->registry->resolve($collection);
            $storage = $this->registry->qualifiedStorageName($resolved['storage_identifier']);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));

            $rows = $this->connection()->select(
                "SELECT id, content, metadata FROM {$storage} WHERE id IN ({$placeholders})",
                array_map(strval(...), $ids),
            );

            $hits = array_map(
                fn (object $row): SearchHit => new SearchHit(
                    id: $row->id,
                    score: 1.0,
                    content: (string) $row->content,
                    metadata: $this->decodeMetadata($row->metadata),
                ),
                $rows,
            );

            return new SearchResult(hits: $hits, count: count($hits));
        });
    }

    public function insert(string $collection, array $documents, ?callable $onProgress = null): void
    {
        $this->ingestDocuments(
            collection: $collection,
            documents: $documents,
            globalMetadata: [],
            upsert: false,
            onProgress: $onProgress,
        );
    }

    public function upsert(string $collection, array $documents, array $globalMetadata = [], ?callable $onProgress = null): void
    {
        $this->ingestDocuments(
            collection: $collection,
            documents: $documents,
            globalMetadata: $globalMetadata,
            upsert: true,
            onProgress: $onProgress,
        );
    }

    public function delete(string $collection, array|string|int $ids): void
    {
        $this->run(function () use ($collection, $ids): void {
            $this->preparePgVector();

            $idList = is_array($ids) ? $ids : [$ids];

            if ($idList === []) {
                return;
            }

            $resolved = $this->registry->resolve($collection);
            $storage = $this->registry->qualifiedStorageName($resolved['storage_identifier']);
            $placeholders = implode(', ', array_fill(0, count($idList), '?'));

            $this->connection()->delete(
                "DELETE FROM {$storage} WHERE id IN ({$placeholders})",
                array_map(strval(...), $idList),
            );
        });
    }

    public function deleteByFilter(string $collection, NormalizedFilter $filter): void
    {
        if ($filter->conditions === []) {
            throw new VectorQueryException('Delete by filter requires at least one filter condition.');
        }

        $this->run(function () use ($collection, $filter): void {
            $this->preparePgVector();

            $resolved = $this->registry->resolve($collection);
            $storage = $this->registry->qualifiedStorageName($resolved['storage_identifier']);
            $translated = $this->filterTranslator->translate($filter);

            $this->connection()->delete(
                "DELETE FROM {$storage} WHERE {$translated['sql']}",
                $translated['bindings'],
            );
        });
    }

    public function listIds(string $collection, ?NormalizedFilter $filter = null, ?string $cursor = null, int $limit = 100): IdPage
    {
        return $this->run(function () use ($collection, $filter, $cursor, $limit): IdPage {
            $this->preparePgVector();

            $resolved = $this->registry->resolve($collection);
            $storage = $this->registry->qualifiedStorageName($resolved['storage_identifier']);
            $translated = $this->filterTranslator->translate($filter);

            $sql = "SELECT id FROM {$storage} WHERE {$translated['sql']}";
            $bindings = $translated['bindings'];

            if ($cursor !== null) {
                $sql .= ' AND id > ?';
                $bindings[] = $cursor;
            }

            $rows = $this->connection()->select("{$sql} ORDER BY id LIMIT ?", [...$bindings, $limit + 1]);

            return IdPage::fromOverfetch(array_map(static fn (object $row): string => (string) $row->id, $rows), $limit);
        });
    }

    public function updateMetadata(string $collection, array $metadataById, bool $merge = true): UpdateMetadataResult
    {
        return $this->run(function () use ($collection, $metadataById, $merge): UpdateMetadataResult {
            $this->preparePgVector();

            $resolved = $this->registry->resolve($collection);
            $storage = $this->registry->qualifiedStorageName($resolved['storage_identifier']);
            $requested = array_map(strval(...), array_keys($metadataById));
            $updated = [];

            $rows = [];
            foreach ($metadataById as $id => $metadata) {
                $rows[] = [(string) $id, json_encode((object) $this->metadataValidator->normalize($metadata), JSON_THROW_ON_ERROR)];
            }

            // Rows written before B3 may hold empty metadata as a JSON array; || on an array would append.
            $value = $merge
                ? "(CASE WHEN jsonb_typeof(documents.metadata) = 'object' THEN documents.metadata ELSE '{}'::jsonb END) || changes.metadata"
                : 'changes.metadata';

            $this->connection()->transaction(function () use ($rows, $storage, $value, &$updated): void {
                foreach (array_chunk($rows, max(1, $this->batchSize())) as $chunk) {
                    $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?::jsonb)'));

                    $returned = $this->connection()->selectFromWriteConnection(
                        "UPDATE {$storage} AS documents SET metadata = {$value}
                         FROM (VALUES {$placeholders}) AS changes(id, metadata)
                         WHERE documents.id = changes.id
                         RETURNING documents.id",
                        array_merge(...$chunk),
                    );

                    array_push($updated, ...array_map(static fn (object $row): string => (string) $row->id, $returned));
                }
            });

            return new UpdateMetadataResult(
                updated: array_values(array_intersect($requested, $updated)),
                unknown: array_values(array_diff($requested, $updated)),
            );
        });
    }

    public function batchSize(): int
    {
        $storeOverride = config('vector.stores.pgvector.batch_size');

        if ($storeOverride !== null) {
            return (int) $storeOverride;
        }

        return (int) ($this->config['batch_size'] ?? config('vector.batch.size', 100));
    }

    public function createCollection(CollectionDefinition $definition): void
    {
        $this->run(function () use ($definition): void {
            $this->preparePgVector();

            $index = PgVectorIndex::fromDefinition($definition, $this->indexType(), $this->configuredIvfflatLists());
            $dimensions = $definition->dimensions ?? $this->embeddings->using()->dimensions();
            $metric = PgVectorMetric::fromString($this->resolveCollectionMetric($definition));

            if ($dimensions < 1) {
                throw new VectorQueryException("Collection [{$definition->name}] dimensions must be at least 1.");
            }

            if ($this->registry->exists($definition->name)) {
                $existing = $this->registry->resolve($definition->name);
                $existingIndex = $this->existingIndex($this->registry->qualifiedStorageName($existing['storage_identifier']), $existing['index_options']);

                if ($existing['dimensions'] === $dimensions
                    && $existing['metric'] === $metric
                    && ($existingIndex === null || $existingIndex->equals($index))) {
                    return;
                }

                throw new VectorQueryException(
                    "Collection [{$definition->name}] already exists with a different definition.",
                );
            }

            $this->connection()->transaction(function () use ($definition, $dimensions, $metric, $index): void {
                $storageIdentifier = $this->registry->register($definition, $dimensions, $metric, $index->toArray());
                $this->createStorage($storageIdentifier, $dimensions, $metric, $index);
            });
        });
    }

    public function deleteCollection(string $name): void
    {
        $this->run(function () use ($name): void {
            $resolved = $this->registry->resolve($name);
            $storage = $this->registry->qualifiedStorageName($resolved['storage_identifier']);

            $this->connection()->statement("DROP TABLE IF EXISTS {$storage}");
            $this->registry->remove($name);
        });
    }

    public function collectionExists(string $name): bool
    {
        return $this->run(function () use ($name): bool {
            $this->preparePgVector();

            return $this->registry->exists($name);
        });
    }

    public function listCollections(): array
    {
        return $this->run(function (): array {
            $this->preparePgVector();

            return $this->registry->listCollectionNames();
        });
    }

    public function healthCheck(): HealthCheckResult
    {
        return $this->run(function (): HealthCheckResult {
            $started = hrtime(true);

            $this->connection()->select('SELECT 1');

            $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);

            return new HealthCheckResult(healthy: true, latencyMs: $latencyMs);
        });
    }

    private function createStorage(string $storageIdentifier, int $dimensions, PgVectorMetric $metric, PgVectorIndex $index): void
    {
        $storage = $this->registry->qualifiedStorageName($storageIdentifier);

        $this->connection()->statement(
            "CREATE TABLE {$storage} (
                id TEXT PRIMARY KEY,
                content TEXT NOT NULL,
                embedding vector({$dimensions}) NOT NULL,
                metadata JSONB NOT NULL DEFAULT '{}'::jsonb
            )",
        );

        $this->connection()->statement($index->createSql($storage, $metric->indexOps()));
    }

    /**
     * The collection's embedding index as it exists in Postgres (with the registered IVFFlat lists),
     * or null when it has none.
     *
     * @param  array{type?: string, options?: array<string, int>}|null  $registered
     */
    private function existingIndex(string $storage, ?array $registered): ?PgVectorIndex
    {
        $indexDefinition = $this->connection()->scalar('SELECT pg_get_indexdef(to_regclass(?))', ["{$storage}_embedding_idx"]);

        return PgVectorIndex::fromExisting(
            is_string($indexDefinition) ? $indexDefinition : null,
            $registered,
            $this->configuredIvfflatLists(),
        );
    }

    /**
     * Ingest documents in store-advised chunks. Each chunk is embedded and written in its own
     * transaction; prior chunks remain persisted if a later chunk fails.
     *
     * @param  list<Document>  $documents
     * @param  array<string, mixed>  $globalMetadata
     * @param  (callable(int, int): void)|null  $onProgress
     */
    private function ingestDocuments(
        string $collection,
        array $documents,
        array $globalMetadata,
        bool $upsert,
        ?callable $onProgress,
    ): void {
        $this->run(function () use ($collection, $documents, $globalMetadata, $upsert, $onProgress): void {
            $this->preparePgVector();

            if ($documents === []) {
                return;
            }

            $resolved = $this->registry->resolve($collection);
            $storage = $this->registry->qualifiedStorageName($resolved['storage_identifier']);
            $total = count($documents);
            $processed = 0;

            foreach ($this->chunkDocuments($documents) as $chunk) {
                $this->connection()->transaction(function () use (
                    $storage,
                    $chunk,
                    $resolved,
                    $collection,
                    $globalMetadata,
                    $upsert,
                ): void {
                    $embedded = $this->resolveDocumentsWithEmbeddings($chunk);

                    if ($upsert) {
                        $this->bulkUpsertDocuments(
                            storage: $storage,
                            documents: $embedded,
                            dimensions: $resolved['dimensions'],
                            collection: $collection,
                            globalMetadata: $globalMetadata,
                        );

                        return;
                    }

                    $this->bulkInsertDocuments(
                        storage: $storage,
                        documents: $embedded,
                        dimensions: $resolved['dimensions'],
                        collection: $collection,
                    );
                });

                $processed += count($chunk);

                if ($onProgress !== null) {
                    $onProgress($processed, $total);
                }
            }

            $index = $this->existingIndex($storage, $resolved['index_options']);

            if ($index?->type === 'ivfflat') {
                $this->rebuildIvfflatIndex($storage, $resolved['metric'], $index);
            }
        });
    }

    /**
     * @param  list<Document>  $documents
     * @return list<list<Document>>
     */
    private function chunkDocuments(array $documents): array
    {
        $size = max(1, $this->batchSize());

        return array_chunk($documents, $size);
    }

    /**
     * @param  list<Document>  $documents
     */
    private function bulkInsertDocuments(
        string $storage,
        array $documents,
        int $dimensions,
        string $collection,
    ): void {
        if ($documents === []) {
            return;
        }

        $placeholders = [];
        $bindings = [];

        foreach ($documents as $document) {
            $metadata = $this->metadataValidator->normalize($document->metadata);
            $vector = $this->requireVector($document, $dimensions, $collection);

            $placeholders[] = '(?, ?, ?::vector, ?::jsonb)';
            array_push(
                $bindings,
                (string) $document->id,
                $document->content,
                PgVectorVectorFormatter::format($vector),
                json_encode((object) $metadata, JSON_THROW_ON_ERROR),
            );
        }

        $values = implode(', ', $placeholders);

        try {
            $this->connection()->insert(
                "INSERT INTO {$storage} (id, content, embedding, metadata) VALUES {$values}",
                $bindings,
            );
        } catch (UniqueConstraintViolationException $exception) {
            preg_match('/Key \(id\)=\((.*)\) already exists/', $exception->getMessage(), $match);

            throw VectorDuplicateDocumentException::forId($collection, $match[1] ?? null);
        }
    }

    /**
     * @param  list<Document>  $documents
     * @param  array<string, mixed>  $globalMetadata
     */
    private function bulkUpsertDocuments(
        string $storage,
        array $documents,
        int $dimensions,
        string $collection,
        array $globalMetadata,
    ): void {
        if ($documents === []) {
            return;
        }

        $placeholders = [];
        $bindings = [];

        foreach ($documents as $document) {
            $metadata = $this->metadataValidator->normalize(array_merge($globalMetadata, $document->metadata));
            $vector = $this->requireVector($document, $dimensions, $collection);

            $placeholders[] = '(?, ?, ?::vector, ?::jsonb)';
            array_push(
                $bindings,
                (string) $document->id,
                $document->content,
                PgVectorVectorFormatter::format($vector),
                json_encode((object) $metadata, JSON_THROW_ON_ERROR),
            );
        }

        $values = implode(', ', $placeholders);

        $this->connection()->statement(
            "INSERT INTO {$storage} (id, content, embedding, metadata)
             VALUES {$values}
             ON CONFLICT (id) DO UPDATE SET
                content = EXCLUDED.content,
                embedding = EXCLUDED.embedding,
                metadata = EXCLUDED.metadata",
            $bindings,
        );
    }

    /**
     * @param  list<Document>  $documents
     * @return list<Document>
     */
    private function resolveDocumentsWithEmbeddings(array $documents): array
    {
        $needsEmbedding = array_values(array_filter(
            $documents,
            static fn (Document $document): bool => $document->vector === null,
        ));

        if ($needsEmbedding === []) {
            return $documents;
        }

        $vectors = TraceSpan::embedding(fn (): array => $this->embeddings->using()->embedBatch(array_map(
            static fn (Document $document): string => $document->content,
            $needsEmbedding,
        )));

        $vectorById = [];
        foreach ($needsEmbedding as $index => $document) {
            $vectorById[(string) $document->id] = $vectors[$index];
        }

        return array_map(
            static function (Document $document) use ($vectorById): Document {
                $vector = $vectorById[(string) $document->id] ?? $document->vector;

                if ($vector === null) {
                    return $document;
                }

                return new Document(
                    id: $document->id,
                    content: $document->content,
                    metadata: $document->metadata,
                    vector: $vector,
                );
            },
            $documents,
        );
    }

    /**
     * @return list<float>
     */
    private function requireVector(Document $document, int $dimensions, string $collection): array
    {
        if ($document->vector === null) {
            throw new VectorQueryException("Document [{$document->id}] could not be embedded.");
        }

        $this->assertVectorDimensions($document->vector, $dimensions, $collection);

        return $document->vector;
    }

    /**
     * @param  list<float>  $vector
     */
    private function assertVectorDimensions(array $vector, int $dimensions, string $collection): void
    {
        if (count($vector) !== $dimensions) {
            throw new VectorDimensionMismatchException(
                "Vector dimensions for collection [{$collection}] must be {$dimensions}, ".count($vector).' given.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $metadata, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function preparePgVector(): void
    {
        if ($this->prepared) {
            return;
        }

        if ((bool) ($this->config['verify_extension'] ?? true)) {
            $this->extensionGuard->ensureAvailable();
        }

        $this->registry->ensureRegistryTable();
        $this->prepared = true;
    }

    private function resolveCollectionMetric(CollectionDefinition $definition): string
    {
        if ($definition->metric !== null) {
            return $definition->metric;
        }

        return (string) ($this->config['metric'] ?? 'cosine');
    }

    private function indexType(): string
    {
        return (string) ($this->config['index'] ?? 'hnsw');
    }

    /**
     * IVFFlat centroids come from the rows, so the index is rebuilt after ingest with
     * lists = min(requested lists, row count).
     */
    private function rebuildIvfflatIndex(string $storage, PgVectorMetric $metric, PgVectorIndex $index): void
    {
        $rowCount = (int) $this->connection()->scalar("SELECT COUNT(*) FROM {$storage}");

        $this->connection()->statement("DROP INDEX IF EXISTS {$storage}_embedding_idx");
        $this->connection()->statement($index->createSql($storage, $metric->indexOps(), $rowCount));
    }

    private function configuredIvfflatLists(): int
    {
        return (int) ($this->config['ivfflat_lists'] ?? 100);
    }

    private function connection(): Connection
    {
        $connectionName = (string) ($this->config['connection'] ?? 'pgsql');

        return $this->db->connection($connectionName);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function run(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (VectorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new VectorConnectionException(
                'Unable to reach the pgvector store: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
