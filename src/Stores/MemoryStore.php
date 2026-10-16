<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores;

use AgenticGraph\Vector\Concerns\InteractsWithBuilder;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\PgVectorMetric;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException;
use AgenticGraph\Vector\Exceptions\VectorDuplicateDocumentException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Stores\Memory\MemoryFilterMatcher;
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

/**
 * Reference store in plain PHP: exact similarity with pgvector's score math, no index, data kept
 * for the life of the store instance (one process). For app tests without Postgres. Index options
 * in a CollectionDefinition are accepted and ignored.
 */
final class MemoryStore implements VectorStoreContract
{
    use InteractsWithBuilder;

    /** @var array<string, array{dimensions: int, metric: PgVectorMetric, documents: array<string, array{content: string, metadata: array<string, mixed>, vector: list<float>}>}> */
    private array $collections = [];

    private readonly StoreCapabilities $capabilities;

    private readonly MemoryFilterMatcher $filterMatcher;

    /**
     * @param  array<string, mixed>  $config
     * @param  StoreCapabilities|null  $capabilities  fewer capabilities to test an app's fallbacks; null is all supported
     */
    public function __construct(
        private readonly array $config,
        private readonly EmbeddingsManager $embeddings,
        private readonly MetadataValidator $metadataValidator,
        ?StoreCapabilities $capabilities = null,
    ) {
        $this->capabilities = $capabilities ?? new StoreCapabilities(
            StoreCapability::ListIds,
            StoreCapability::UpdateMetadata,
            StoreCapability::DeleteByFilter,
            StoreCapability::FilterNot,
            StoreCapability::FilterContains,
            StoreCapability::FilterRange,
        );
        $this->filterMatcher = new MemoryFilterMatcher;
    }

    public function search(SearchQuery $query): SearchResult
    {
        $collection = $this->resolve($query->collection);
        $this->assertVectorDimensions($query->vector, $collection['dimensions'], $query->collection);

        $metric = $collection['metric'];
        $maxDistance = $query->minScore !== null ? $metric->maxDistanceForMinScore($query->minScore) : null;
        $scored = [];

        foreach ($collection['documents'] as $id => $document) {
            if (! $this->filterMatcher->matches($query->filter, $document['metadata'])) {
                continue;
            }

            $distance = $this->distance($metric, $query->vector, $document['vector']);

            if ($maxDistance === null || $distance <= $maxDistance) {
                $scored[] = [(string) $id, $distance];
            }
        }

        usort($scored, static fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        $hits = array_map(
            fn (array $entry): SearchHit => $this->hit($collection, $entry[0], $metric->distanceToSimilarity($entry[1])),
            array_slice($scored, 0, max(0, $query->topK)),
        );

        return new SearchResult(hits: $hits, count: count($hits));
    }

    public function capabilities(): StoreCapabilities
    {
        return $this->capabilities;
    }

    public function fetch(string $collection, array $ids): SearchResult
    {
        $resolved = $this->resolve($collection);
        $hits = [];

        foreach (array_unique(array_map(strval(...), $ids)) as $id) {
            if (isset($resolved['documents'][$id])) {
                $hits[] = $this->hit($resolved, $id, 1.0);
            }
        }

        return new SearchResult(hits: $hits, count: count($hits));
    }

    public function insert(string $collection, array $documents, ?callable $onProgress = null): void
    {
        $this->ingest($collection, $documents, [], false, $onProgress);
    }

    public function upsert(string $collection, array $documents, array $globalMetadata = [], ?callable $onProgress = null): void
    {
        $this->ingest($collection, $documents, $globalMetadata, true, $onProgress);
    }

    public function delete(string $collection, array|string|int $ids): void
    {
        $this->resolve($collection);

        foreach (is_array($ids) ? $ids : [$ids] as $id) {
            unset($this->collections[$collection]['documents'][(string) $id]);
        }
    }

    public function deleteByFilter(string $collection, NormalizedFilter $filter): void
    {
        if ($filter->conditions === []) {
            throw new VectorQueryException('Delete by filter requires at least one filter condition.');
        }

        foreach ($this->resolve($collection)['documents'] as $id => $document) {
            if ($this->filterMatcher->matches($filter, $document['metadata'])) {
                unset($this->collections[$collection]['documents'][$id]);
            }
        }
    }

    public function listIds(string $collection, ?NormalizedFilter $filter = null, ?string $cursor = null, int $limit = 100): IdPage
    {
        $ids = [];

        foreach ($this->resolve($collection)['documents'] as $id => $document) {
            $id = (string) $id;

            if (($cursor === null || strcmp($id, $cursor) > 0) && $this->filterMatcher->matches($filter, $document['metadata'])) {
                $ids[] = $id;
            }
        }

        usort($ids, strcmp(...));

        return IdPage::fromOverfetch(array_slice($ids, 0, $limit + 1), $limit);
    }

    public function updateMetadata(string $collection, array $metadataById, bool $merge = true): UpdateMetadataResult
    {
        $documents = $this->resolve($collection)['documents'];
        $changes = [];

        foreach ($metadataById as $id => $metadata) {
            $changes[(string) $id] = $this->storable($this->metadataValidator->normalize($metadata));
        }

        $updated = [];
        $unknown = [];

        foreach ($changes as $id => $metadata) {
            $id = (string) $id;

            if (! isset($documents[$id])) {
                $unknown[] = $id;

                continue;
            }

            $this->collections[$collection]['documents'][$id]['metadata'] = $merge
                ? array_replace($documents[$id]['metadata'], $metadata)
                : $metadata;
            $updated[] = $id;
        }

        return new UpdateMetadataResult(updated: $updated, unknown: $unknown);
    }

    public function batchSize(): int
    {
        return max(1, (int) ($this->config['batch_size'] ?? config('vector.batch.size', 100)));
    }

    public function createCollection(CollectionDefinition $definition): void
    {
        $dimensions = $definition->dimensions ?? $this->embeddings->using()->dimensions();
        $metric = PgVectorMetric::fromString($definition->metric ?? (string) ($this->config['metric'] ?? 'cosine'));

        if ($dimensions < 1) {
            throw new VectorQueryException("Collection [{$definition->name}] dimensions must be at least 1.");
        }

        $existing = $this->collections[$definition->name] ?? null;

        if ($existing !== null) {
            if ($existing['dimensions'] === $dimensions && $existing['metric'] === $metric) {
                return;
            }

            throw new VectorQueryException("Collection [{$definition->name}] already exists with a different definition.");
        }

        $this->collections[$definition->name] = ['dimensions' => $dimensions, 'metric' => $metric, 'documents' => []];
    }

    public function deleteCollection(string $name): void
    {
        $this->resolve($name);

        unset($this->collections[$name]);
    }

    public function collectionExists(string $name): bool
    {
        return isset($this->collections[$name]);
    }

    public function listCollections(): array
    {
        $names = array_map(strval(...), array_keys($this->collections));
        sort($names, SORT_STRING);

        return $names;
    }

    public function healthCheck(): HealthCheckResult
    {
        return new HealthCheckResult(healthy: true, latencyMs: 0);
    }

    /**
     * Chunks of batchSize(): each chunk is embedded, validated and written as a whole, so a failing
     * chunk writes nothing and earlier chunks stay (as pgvector's per-chunk transactions).
     *
     * @param  list<Document>  $documents
     * @param  array<string, mixed>  $globalMetadata
     * @param  (callable(int, int): void)|null  $onProgress
     */
    private function ingest(string $collection, array $documents, array $globalMetadata, bool $upsert, ?callable $onProgress): void
    {
        $resolved = $this->resolve($collection);
        $total = count($documents);
        $processed = 0;

        foreach (array_chunk($documents, $this->batchSize()) as $chunk) {
            $vectors = $this->embedMissing($chunk);
            $rows = [];

            foreach ($chunk as $index => $document) {
                $id = (string) $document->id;
                $metadata = $this->metadataValidator->normalize($upsert ? array_merge($globalMetadata, $document->metadata) : $document->metadata);
                $vector = $document->vector ?? $vectors[$index] ?? null;

                if ($vector === null) {
                    throw new VectorQueryException("Document [{$document->id}] could not be embedded.");
                }

                $this->assertVectorDimensions($vector, $resolved['dimensions'], $collection);

                if (! $upsert && (isset($resolved['documents'][$id]) || isset($rows[$id]))) {
                    throw VectorDuplicateDocumentException::forId($collection, $id);
                }

                $rows[$id] = [
                    'content' => $document->content,
                    'metadata' => $this->storable($metadata),
                    'vector' => array_map(floatval(...), $vector),
                ];
            }

            foreach ($rows as $id => $row) {
                $this->collections[$collection]['documents'][(string) $id] = $row;
            }

            $resolved = $this->resolve($collection);
            $processed += count($chunk);

            if ($onProgress !== null) {
                $onProgress($processed, $total);
            }
        }
    }

    /**
     * @param  list<Document>  $chunk
     * @return array<int, list<float>> vectors by chunk index, for documents without one
     */
    private function embedMissing(array $chunk): array
    {
        $missing = array_filter($chunk, static fn (Document $document): bool => $document->vector === null);

        if ($missing === []) {
            return [];
        }

        $vectors = TraceSpan::embedding(fn (): array => $this->embeddings->using()->embedBatch(array_values(array_map(
            static fn (Document $document): string => $document->content,
            $missing,
        ))));

        return array_combine(array_keys($missing), $vectors);
    }

    /**
     * Metadata as it comes back from a JSON store (pgvector JSONB), so both stores return the same types.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function storable(array $metadata): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode((object) $metadata, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param  array{dimensions: int, metric: PgVectorMetric, documents: array<string, array{content: string, metadata: array<string, mixed>, vector: list<float>}>}  $collection
     */
    private function hit(array $collection, string $id, float $score): SearchHit
    {
        $document = $collection['documents'][$id];

        return new SearchHit(id: $id, score: $score, content: $document['content'], metadata: $document['metadata']);
    }

    /**
     * pgvector's distance for the metric: cosine distance, Euclidean distance, negative inner product.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function distance(PgVectorMetric $metric, array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $squared = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
            $squared += ($value - $b[$i]) ** 2;
        }

        return match ($metric) {
            PgVectorMetric::Cosine => $normA > 0.0 && $normB > 0.0 ? 1.0 - $dot / (sqrt($normA) * sqrt($normB)) : 1.0,
            PgVectorMetric::L2 => sqrt($squared),
            PgVectorMetric::InnerProduct => -$dot,
        };
    }

    /**
     * @return array{dimensions: int, metric: PgVectorMetric, documents: array<string, array{content: string, metadata: array<string, mixed>, vector: list<float>}>}
     */
    private function resolve(string $collection): array
    {
        return $this->collections[$collection]
            ?? throw new VectorCollectionNotFoundException("Collection [{$collection}] was not found.");
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
}
