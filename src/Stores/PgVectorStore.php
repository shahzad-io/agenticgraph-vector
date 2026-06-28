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
use AgenticGraph\Vector\Exceptions\VectorExtensionMissingException;
use AgenticGraph\Vector\Exceptions\VectorInvalidMetadataException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Stores\PgVector\PgVectorCollectionRegistry;
use AgenticGraph\Vector\Stores\PgVector\PgVectorExtensionGuard;
use AgenticGraph\Vector\Stores\PgVector\PgVectorFilterTranslator;
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
            $collection = $this->registry->resolve($query->collection);
            $this->assertVectorDimensions($query->vector, $collection['dimensions'], $query->collection);

            $metric = $collection['metric'];
            $storage = $this->registry->qualifiedStorageName($collection['storage_identifier']);
            $operator = $metric->distanceOperator();
            $vector = PgVectorVectorFormatter::format($query->vector);
            $filter = $this->filterTranslator->translate($query->filter);

            $distanceExpression = "embedding {$operator} ?::vector";
            $scoreExpression = match ($metric) {
                PgVectorMetric::Cosine => "(1 - ({$distanceExpression}))",
                PgVectorMetric::L2 => "(1 / (1 + ({$distanceExpression})))",
                PgVectorMetric::InnerProduct => "(-({$distanceExpression}))",
            };

            $sql = "SELECT id, content, metadata, {$scoreExpression} AS score FROM {$storage} WHERE {$filter['sql']}";
            $bindings = [$vector, ...$filter['bindings']];

            if ($query->minScore !== null) {
                $sql .= " AND {$scoreExpression} >= ?";
                $bindings[] = $vector;
                $bindings[] = $query->minScore;
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

    public function insert(string $collection, array $documents): void
    {
        $this->run(function () use ($collection, $documents): void {
            $this->preparePgVector();

            if ($documents === []) {
                return;
            }

            $resolved = $this->registry->resolve($collection);
            $storage = $this->registry->qualifiedStorageName($resolved['storage_identifier']);

            foreach ($documents as $document) {
                $this->insertDocument($storage, $document, $resolved['dimensions'], $collection);
            }
        });
    }

    public function upsert(string $collection, array $documents, array $globalMetadata = []): void
    {
        $this->run(function () use ($collection, $documents, $globalMetadata): void {
            $this->preparePgVector();

            if ($documents === []) {
                return;
            }

            $resolved = $this->registry->resolve($collection);
            $storage = $this->registry->qualifiedStorageName($resolved['storage_identifier']);

            foreach ($documents as $document) {
                $metadata = array_merge($globalMetadata, $document->metadata);
                $this->metadataValidator->validate($metadata);
                $vector = $this->requireVector($document, $resolved['dimensions'], $collection);

                $this->connection()->statement(
                    "INSERT INTO {$storage} (id, content, embedding, metadata)
                     VALUES (?, ?, ?::vector, ?::jsonb)
                     ON CONFLICT (id) DO UPDATE SET
                        content = EXCLUDED.content,
                        embedding = EXCLUDED.embedding,
                        metadata = EXCLUDED.metadata",
                    [
                        (string) $document->id,
                        $document->content,
                        PgVectorVectorFormatter::format($vector),
                        json_encode($metadata, JSON_THROW_ON_ERROR),
                    ],
                );
            }
        });
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
        return (int) ($this->config['batch_size'] ?? config('vector.batch.size', 100));
    }

    public function createCollection(CollectionDefinition $definition): void
    {
        $this->run(function () use ($definition): void {
            $this->preparePgVector();

            $dimensions = $definition->dimensions ?? $this->embeddings->using()->dimensions();
            $metric = PgVectorMetric::fromString($definition->metric);

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
            $lists = (int) ($this->config['ivfflat_lists'] ?? 100);
            $this->connection()->statement(
                "CREATE INDEX IF NOT EXISTS {$storage}_embedding_idx ON {$storage} USING ivfflat (embedding {$ops}) WITH (lists = {$lists})",
            );

            return;
        }

        $this->connection()->statement(
            "CREATE INDEX IF NOT EXISTS {$storage}_embedding_idx ON {$storage} USING hnsw (embedding {$ops})",
        );
    }

    private function insertDocument(string $storage, Document $document, int $dimensions, string $collection): void
    {
        $this->metadataValidator->validate($document->metadata);
        $vector = $this->requireVector($document, $dimensions, $collection);

        $this->connection()->insert(
            "INSERT INTO {$storage} (id, content, embedding, metadata) VALUES (?, ?, ?::vector, ?::jsonb)",
            [
                (string) $document->id,
                $document->content,
                PgVectorVectorFormatter::format($vector),
                json_encode($document->metadata, JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * @return list<float>
     */
    private function requireVector(Document $document, int $dimensions, string $collection): array
    {
        if ($document->vector === null) {
            throw new VectorQueryException("Document [{$document->id}] requires a precomputed vector.");
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
        } catch (VectorCollectionNotFoundException|VectorDimensionMismatchException|VectorExtensionMissingException|VectorInvalidMetadataException|VectorQueryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new VectorConnectionException(
                'Unable to reach the pgvector store: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
