<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores;

use AgenticGraph\Vector\Concerns\InteractsWithBuilder;
use AgenticGraph\Vector\Contracts\AliasStoreContract;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\PgVectorMetric;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException;
use AgenticGraph\Vector\Exceptions\VectorDuplicateDocumentException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Stores\Memory\MemoryFilterMatcher;
use AgenticGraph\Vector\Stores\Memory\MemoryKeywordMatcher;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\Support\ReciprocalRankFusion;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\HealthCheckResult;
use AgenticGraph\Vector\ValueObjects\IdPage;
use AgenticGraph\Vector\ValueObjects\MigrationState;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;
use AgenticGraph\Vector\ValueObjects\UpdateMetadataResult;

/**
 * Reference store in plain PHP: exact similarity with pgvector's score math, no index, data kept
 * for the life of the store instance (one process). For app tests without Postgres. Index options
 * in a CollectionDefinition are accepted and ignored. Aliases and migrations follow pgvector's rules;
 * there are no concurrent writers, so nothing locks. Keyword search uses `simple` semantics for every document
 * (MemoryKeywordMatcher); the language map is recorded, not applied.
 */
final class MemoryStore implements AliasStoreContract, VectorStoreContract
{
    use InteractsWithBuilder;

    /** @var array<string, array{dimensions: int, metric: PgVectorMetric, index: array{type: string, options: array<string, int>}|null, embedding: array{provider: string, model: string, dimensions: int}|null, keyword: array{languages: array<string, string>, language_field: string}|null, documents: array<string, array{content: string, metadata: array<string, mixed>, vector: list<float>}>}> */
    private array $collections = [];

    /** @var array<string, string> alias => collection */
    private array $aliases = [];

    /** @var array<string, array<string, MigrationState>> alias => target => migration, oldest first */
    private array $migrations = [];

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
            StoreCapability::Keyword,
            StoreCapability::Aliases,
        );
        $this->filterMatcher = new MemoryFilterMatcher;
    }

    public function search(SearchQuery $query): SearchResult
    {
        $collection = $this->resolve($query->collection);
        $this->assertVectorDimensions($query->vector, $collection['dimensions'], $query->collection);

        if ($query->keywords !== null && $collection['keyword'] === null) {
            throw new VectorQueryException(
                "Collection [{$query->collection}] has no keyword index, so it cannot run a hybrid search. Migrate it with keyword search: Vector::migrate('{$query->collection}', new CollectionDefinition('{$query->collection}', keyword: true)) or php artisan vector:migrate {$query->collection} --keyword.",
            );
        }

        if ($query->keywords !== null && ! in_array($query->keywordMatch, SearchQuery::KEYWORD_MATCH_MODES, true)) {
            throw new VectorQueryException('Keyword match must be one of: '.implode(', ', SearchQuery::KEYWORD_MATCH_MODES).". Got [{$query->keywordMatch}].");
        }

        $metric = $collection['metric'];
        $maxDistance = $query->minScore !== null ? $metric->maxDistanceForMinScore($query->minScore) : null;
        $keywords = $query->keywords !== null ? new MemoryKeywordMatcher($query->keywords, $query->keywordMatch) : null;
        $distances = [];
        $keywordScores = [];

        foreach ($collection['documents'] as $id => $document) {
            if (! $this->filterMatcher->matches($query->filter, $document['metadata'])) {
                continue;
            }

            $id = (string) $id;
            $distances[$id] = $this->distance($metric, $query->vector, $document['vector']);
            $keywordScore = $keywords?->score($document['content']);

            if ($keywordScore !== null) {
                $keywordScores[$id] = $keywordScore;
            }
        }

        $dense = array_map(strval(...), array_keys($distances));
        usort($dense, static fn (string $a, string $b): int => $distances[$a] <=> $distances[$b] ?: strcmp($a, $b));
        $passes = static fn (string $id): bool => $maxDistance === null || $distances[$id] <= $maxDistance;

        if ($keywords === null) {
            $hits = array_map(
                fn (string $id): SearchHit => $this->hit($collection, $id, $metric->distanceToSimilarity($distances[$id])),
                array_slice(array_values(array_filter($dense, $passes)), 0, max(0, $query->topK)),
            );

            return new SearchResult(hits: $hits, count: count($hits));
        }

        $limit = max($query->depth, $query->topK);
        $keywordIds = array_map(strval(...), array_keys($keywordScores));
        usort($keywordIds, static fn (string $a, string $b): int => $keywordScores[$b] <=> $keywordScores[$a] ?: strcmp($a, $b));
        $hits = [];

        foreach (ReciprocalRankFusion::fuse(array_slice($dense, 0, $limit), array_slice($keywordIds, 0, $limit), $query->fusionK, $query->keywordWeight) as $id => $fused) {
            $id = (string) $id;

            if (! $passes($id)) {
                continue;
            }

            $score = $metric->distanceToSimilarity($distances[$id]);
            $hits[] = $this->hit($collection, $id, $score, ['vector' => $score, 'keyword' => $keywordScores[$id] ?? null, 'fused' => $fused]);

            if (count($hits) === $query->topK) {
                break;
            }
        }

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
        $collection = $this->physical($collection);

        foreach (is_array($ids) ? $ids : [$ids] as $id) {
            unset($this->collections[$collection]['documents'][(string) $id]);
        }
    }

    public function deleteByFilter(string $collection, NormalizedFilter $filter): void
    {
        if ($filter->conditions === []) {
            throw new VectorQueryException('Delete by filter requires at least one filter condition.');
        }

        $collection = $this->physical($collection);

        foreach ($this->collections[$collection]['documents'] as $id => $document) {
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
        $collection = $this->physical($collection);
        $documents = $this->collections[$collection]['documents'];
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
        $dimensions = $definition->dimensions ?? $this->embeddings->using($definition->embeddings)->dimensions();
        $metric = PgVectorMetric::fromString($definition->metric ?? (string) ($this->config['metric'] ?? 'cosine'));
        $keyword = $definition->keywordSettings();

        if ($dimensions < 1) {
            throw new VectorQueryException("Collection [{$definition->name}] dimensions must be at least 1.");
        }

        $existing = $this->collections[$this->aliases[$definition->name] ?? $definition->name] ?? null;

        if ($existing !== null) {
            if ($existing['dimensions'] === $dimensions
                && $existing['metric'] === $metric
                && ($definition->embeddings === null || $definition->embeddings === ($existing['embedding']['provider'] ?? null))
                && ($definition->keyword === null || $keyword === $existing['keyword'])) {
                return;
            }

            throw new VectorQueryException(
                "Collection [{$definition->name}] already exists with a different definition. Change it with Vector::migrate() (php artisan vector:migrate).",
            );
        }

        $this->collections[$definition->name] = [
            'dimensions' => $dimensions,
            'metric' => $metric,
            'index' => $definition->index !== null ? ['type' => $definition->index, 'options' => $definition->indexOptions] : null,
            'embedding' => $this->embeddings->record($definition->embeddings, $dimensions),
            'keyword' => $keyword,
            'documents' => [],
        ];
    }

    public function deleteCollection(string $name): void
    {
        if (isset($this->aliases[$name])) {
            throw new VectorQueryException("Collection [{$name}] is an alias. Delete the alias with deleteAlias(), or delete its collection [{$this->aliases[$name]}].");
        }

        $this->resolve($name);
        $alias = array_search($name, $this->aliases, true);

        if ($alias !== false) {
            throw new VectorQueryException("Collection [{$name}] is the target of alias [{$alias}]. Delete the alias first.");
        }

        unset($this->collections[$name]);
    }

    public function collectionExists(string $name): bool
    {
        return isset($this->collections[$name]) || isset($this->aliases[$name]);
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

    public function describeCollection(string $name): array
    {
        $collection = $this->physical($name);
        $resolved = $this->collections[$collection];

        return [
            'collection' => $collection,
            'dimensions' => $resolved['dimensions'],
            'metric' => $resolved['metric']->value,
            'index' => $resolved['index'],
            'embedding' => $resolved['embedding'],
            'keyword' => $resolved['keyword'],
        ];
    }

    public function listAliases(): array
    {
        $aliases = $this->aliases;
        ksort($aliases, SORT_STRING);

        return $aliases;
    }

    public function deleteAlias(string $alias): void
    {
        if (! isset($this->aliases[$alias])) {
            throw new VectorCollectionNotFoundException("Alias [{$alias}] was not found.");
        }

        $migration = $this->migration($alias);

        if ($migration?->unfinished()) {
            throw new VectorQueryException("Alias [{$alias}] has an unfinished migration to [{$migration->target}] ({$migration->phase}). Run vector:migrate {$alias} again to finish it, then delete the alias.");
        }

        unset($this->aliases[$alias], $this->migrations[$alias]);
    }

    public function pointAlias(string $alias, string $collection): void
    {
        if (! isset($this->aliases[$alias])) {
            throw new VectorCollectionNotFoundException("Alias [{$alias}] was not found.");
        }

        if (! isset($this->collections[$collection])) {
            throw new VectorCollectionNotFoundException("Collection [{$collection}] was not found.");
        }

        $this->aliases[$alias] = $collection;
    }

    public function adoptCollection(string $name, string $physical): void
    {
        if ($this->collectionExists($physical)) {
            throw new VectorQueryException("Collection [{$physical}] already exists.");
        }

        if (isset($this->aliases[$name]) || ! isset($this->collections[$name])) {
            throw new VectorCollectionNotFoundException("Collection [{$name}] was not found.");
        }

        $this->collections[$physical] = $this->collections[$name];
        unset($this->collections[$name]);
        $this->aliases[$name] = $physical;
    }

    public function countDocuments(string $collection): int
    {
        return count($this->resolve($collection)['documents']);
    }

    public function migration(string $alias): ?MigrationState
    {
        $migrations = $this->migrations[$alias] ?? [];

        return $migrations === [] ? null : $migrations[array_key_last($migrations)];
    }

    public function saveMigration(MigrationState $state): void
    {
        $this->migrations[$state->alias][$state->target] = $state;
    }

    public function migrateBatch(MigrationState $state, int $limit): MigrationState
    {
        $source = $this->collections[$this->physical($state->source)]['documents'];
        $this->physical($state->target);

        $ids = array_values(array_filter(
            array_map(strval(...), array_keys($source)),
            static fn (string $id): bool => $state->lastId === null || strcmp($id, $state->lastId) > 0,
        ));
        usort($ids, strcmp(...));
        $page = array_slice($ids, 0, $limit);

        $this->copyDocuments($state, $source, $page);

        $next = $state->withProgress($state->copied + count($page), $page === [] ? $state->lastId : $page[array_key_last($page)]);
        $next = count($page) < $limit ? $next->withPhase('catching_up') : $next;
        $this->saveMigration($next);

        return $next;
    }

    public function catchUpMigration(MigrationState $state): int
    {
        return $this->catchUpPass($state, null);
    }

    public function finishMigration(MigrationState $state, int $threshold): MigrationState
    {
        $this->catchUpPass($state, $threshold);

        $source = $this->collections[$this->physical($state->source)]['documents'];
        $target = $this->collections[$this->physical($state->target)]['documents'];

        if (count($source) !== count($target)) {
            throw new VectorQueryException("Migration of [{$state->alias}] did not switch: the source holds ".count($source).' documents, the target '.count($target).'.');
        }

        $sample = $source === [] ? [] : (array) array_rand($source, min(20, count($source)));

        foreach ($sample as $id) {
            if (! isset($target[$id]) || $this->differs($state, $source[$id], $target[$id])) {
                throw new VectorQueryException("Migration of [{$state->alias}] did not switch: sampled documents differ between source and target.");
            }
        }

        $this->pointAlias($state->alias, $state->target);
        $switched = $state->withPhase('switched');
        $this->saveMigration($switched);

        return $switched;
    }

    /**
     * @param  array<string, array{content: string, metadata: array<string, mixed>, vector: list<float>}>  $source
     * @param  list<string>  $ids
     */
    private function copyDocuments(MigrationState $state, array $source, array $ids): void
    {
        if ($state->mode === 'copy') {
            foreach ($ids as $id) {
                $this->collections[$state->target]['documents'][$id] = $source[$id];
            }

            return;
        }

        $this->ingest($state->target, array_map(
            static fn (string $id): Document => new Document(id: $id, content: $source[$id]['content'], metadata: $source[$id]['metadata']),
            $ids,
        ), [], true, null);
    }

    private function catchUpPass(MigrationState $state, ?int $threshold): int
    {
        $source = $this->collections[$this->physical($state->source)]['documents'];
        $target = $this->collections[$this->physical($state->target)]['documents'];
        $rewrite = [];
        $metadataOnly = [];

        foreach ($source as $id => $document) {
            $id = (string) $id;

            if (isset($target[$id]) && ! $this->differs($state, $document, $target[$id])) {
                continue;
            }

            if ($state->mode === 'reembed' && $document['content'] === ($target[$id]['content'] ?? null)) {
                $metadataOnly[] = $id;
            } else {
                $rewrite[] = $id;
            }
        }

        $missing = array_map(strval(...), array_keys(array_diff_key($target, $source)));
        $difference = count($rewrite) + count($metadataOnly) + count($missing);

        if ($threshold !== null && $difference > $threshold) {
            throw new VectorQueryException(
                "Migration of [{$state->alias}] did not switch: {$difference} documents changed since the last pass, above catch_up_threshold {$threshold}. Run it again to continue.",
            );
        }

        foreach ($metadataOnly as $id) {
            $this->collections[$state->target]['documents'][$id]['metadata'] = $source[$id]['metadata'];
        }

        $this->copyDocuments($state, $source, $rewrite);

        foreach ($missing as $id) {
            unset($this->collections[$state->target]['documents'][$id]);
        }

        return $difference;
    }

    /**
     * @param  array{content: string, metadata: array<string, mixed>, vector: list<float>}  $source
     * @param  array{content: string, metadata: array<string, mixed>, vector: list<float>}  $target
     */
    private function differs(MigrationState $state, array $source, array $target): bool
    {
        return $state->mode === 'reembed'
            ? $source['content'] !== $target['content'] || $source['metadata'] !== $target['metadata']
            : $source !== $target;
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
        $name = $collection;
        $this->physical($name);
        $total = count($documents);
        $processed = 0;

        foreach (array_chunk($documents, $this->batchSize()) as $chunk) {
            // Each chunk resolves the alias, so a switch mid-ingest moves the rest (as pgvector).
            $collection = $this->physical($name);
            $resolved = $this->collections[$collection];
            $vectors = $this->embedMissing($chunk, $resolved['embedding']['provider'] ?? null);
            $rows = [];

            foreach ($chunk as $index => $document) {
                $id = (string) $document->id;
                $metadata = $this->metadataValidator->normalize($upsert ? array_merge($globalMetadata, $document->metadata) : $document->metadata);
                $vector = $document->vector ?? $vectors[$index] ?? null;

                if ($vector === null) {
                    throw new VectorQueryException("Document [{$document->id}] could not be embedded.");
                }

                $this->assertVectorDimensions($vector, $resolved['dimensions'], $name);

                if (! $upsert && (isset($resolved['documents'][$id]) || isset($rows[$id]))) {
                    throw VectorDuplicateDocumentException::forId($name, $id);
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
    private function embedMissing(array $chunk, ?string $provider): array
    {
        $missing = array_filter($chunk, static fn (Document $document): bool => $document->vector === null);

        if ($missing === []) {
            return [];
        }

        $vectors = TraceSpan::embedding(fn (): array => $this->embeddings->using($provider)->embedBatch(array_values(array_map(
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
     * @param  array{dimensions: int, metric: PgVectorMetric, embedding: array{provider: string, model: string, dimensions: int}|null, documents: array<string, array{content: string, metadata: array<string, mixed>, vector: list<float>}>}  $collection
     * @param  array{vector: float, keyword: float|null, fused: float}|null  $scores
     */
    private function hit(array $collection, string $id, float $score, ?array $scores = null): SearchHit
    {
        $document = $collection['documents'][$id];

        return new SearchHit(id: $id, score: $score, content: $document['content'], metadata: $document['metadata'], scores: $scores);
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
     * @return array{dimensions: int, metric: PgVectorMetric, index: array{type: string, options: array<string, int>}|null, embedding: array{provider: string, model: string, dimensions: int}|null, keyword: array{languages: array<string, string>, language_field: string}|null, documents: array<string, array{content: string, metadata: array<string, mixed>, vector: list<float>}>}
     */
    private function resolve(string $collection): array
    {
        return $this->collections[$this->physical($collection)];
    }

    /**
     * The collection a name points to: the alias's target, or the name itself.
     */
    private function physical(string $name): string
    {
        $collection = $this->aliases[$name] ?? $name;

        if (! isset($this->collections[$collection])) {
            throw new VectorCollectionNotFoundException("Collection [{$name}] was not found.");
        }

        return $collection;
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
