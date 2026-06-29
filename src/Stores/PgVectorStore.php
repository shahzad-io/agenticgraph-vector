<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores;

use AgenticGraph\Vector\Concerns\InteractsWithBuilder;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\PgVectorMetric;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorConnectionException;
use AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException;
use AgenticGraph\Vector\Exceptions\VectorEmbeddingException;
use AgenticGraph\Vector\Exceptions\VectorExtensionMissingException;
use AgenticGraph\Vector\Exceptions\VectorInvalidMetadataException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Stores\PgVector\PgVectorCollectionRegistry;
use AgenticGraph\Vector\Stores\PgVector\PgVectorExtensionGuard;
use AgenticGraph\Vector\Stores\PgVector\PgVectorFilterTranslator;
use AgenticGraph\Vector\Stores\PgVector\PgVectorSearchOptions;
use AgenticGraph\Vector\Stores\PgVector\PgVectorVectorFormatter;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\HealthCheckResult;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class PgVectorStore implements VectorStoreContract
{
    use InteractsWithBuilder;

    private readonly PgVectorCollectionRegistry $registry;

    private readonly PgVectorFilterTranslator $filterTranslator;

    private readonly PgVectorExtensionGuard $extensionGuard;

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
        return StoreCapabilities::pgvector();
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
        if (! $this->capabilities()->supportsDeleteByFilter()) {
            throw new VectorQueryException('Delete by filter is not supported by this store.');
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

            $dimensions = $definition->dimensions ?? $this->embeddings->using()->dimensions();
            $metric = PgVectorMetric::fromString($this->resolveCollectionMetric($definition));

            if ($dimensions < 1) {
                throw new VectorQueryException("Collection [{$definition->name}] dimensions must be at least 1.");
            }

            if ($this->registry->exists($definition->name)) {
                $existing = $this->registry->resolve($definition->name);

                if ($existing['dimensions'] === $dimensions && $existing['metric'] === $metric) {
                    return;
                }

                throw new VectorQueryException(
                    "Collection [{$definition->name}] already exists with a different definition.",
                );
            }

            $this->connection()->transaction(function () use ($definition, $dimensions, $metric): void {
                $storageIdentifier = $this->registry->register($definition, $dimensions, $metric);
                $this->createStorage($storageIdentifier, $dimensions, $metric);
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

    private function createStorage(string $storageIdentifier, int $dimensions, PgVectorMetric $metric): void
    {
        $storage = $this->registry->qualifiedStorageName($storageIdentifier);
        $indexType = (string) ($this->config['index'] ?? 'hnsw');
        $ops = $metric->indexOps();

        $this->connection()->statement(
            "CREATE TABLE {$storage} (
                id TEXT PRIMARY KEY,
                content TEXT NOT NULL,
                embedding vector({$dimensions}) NOT NULL,
                metadata JSONB NOT NULL DEFAULT '{}'::jsonb
            )",
        );

        if ($indexType === 'ivfflat') {
            $this->createIvfflatIndex($storage, $metric);

            return;
        }

        $this->connection()->statement(
            "CREATE INDEX IF NOT EXISTS {$storage}_embedding_idx ON {$storage} USING hnsw (embedding {$ops})",
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

            if ($this->indexType() === 'ivfflat') {
                $this->createIvfflatIndex(
                    $this->registry->qualifiedStorageName($resolved['storage_identifier']),
                    $resolved['metric'],
                );
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
            $this->metadataValidator->validate($document->metadata);
            $vector = $this->requireVector($document, $dimensions, $collection);

            $placeholders[] = '(?, ?, ?::vector, ?::jsonb)';
            array_push(
                $bindings,
                (string) $document->id,
                $document->content,
                PgVectorVectorFormatter::format($vector),
                json_encode($document->metadata, JSON_THROW_ON_ERROR),
            );
        }

        $values = implode(', ', $placeholders);

        $this->connection()->insert(
            "INSERT INTO {$storage} (id, content, embedding, metadata) VALUES {$values}",
            $bindings,
        );
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
            $metadata = array_merge($globalMetadata, $document->metadata);
            $this->metadataValidator->validate($metadata);
            $vector = $this->requireVector($document, $dimensions, $collection);

            $placeholders[] = '(?, ?, ?::vector, ?::jsonb)';
            array_push(
                $bindings,
                (string) $document->id,
                $document->content,
                PgVectorVectorFormatter::format($vector),
                json_encode($metadata, JSON_THROW_ON_ERROR),
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

        $vectors = $this->embeddings->using()->embedBatch(array_map(
            static fn (Document $document): string => $document->content,
            $needsEmbedding,
        ));

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
        $this->extensionGuard->ensureAvailable();
        $this->registry->ensureRegistryTable();
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

    private function createIvfflatIndex(string $storage, PgVectorMetric $metric): void
    {
        $rowCount = (int) $this->connection()->scalar("SELECT COUNT(*) FROM {$storage}");
        $lists = $this->resolveIvfflatLists($rowCount);
        $ops = $metric->indexOps();
        $indexName = "{$storage}_embedding_idx";

        $this->connection()->statement("DROP INDEX IF EXISTS {$indexName}");
        $this->connection()->statement(
            "CREATE INDEX {$indexName} ON {$storage} USING ivfflat (embedding {$ops}) WITH (lists = {$lists})",
        );
    }

    private function resolveIvfflatLists(int $rowCount): int
    {
        $configured = (int) ($this->config['ivfflat_lists'] ?? 100);
        $effectiveRows = max(1, $rowCount);

        return max(1, min($configured, $effectiveRows));
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
        } catch (VectorCollectionNotFoundException|VectorDimensionMismatchException|VectorEmbeddingException|VectorExtensionMissingException|VectorInvalidMetadataException|VectorQueryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new VectorConnectionException(
                'Unable to reach the pgvector store: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
