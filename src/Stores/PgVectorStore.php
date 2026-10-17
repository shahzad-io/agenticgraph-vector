<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores;

use AgenticGraph\Vector\Concerns\InteractsWithBuilder;
use AgenticGraph\Vector\Contracts\AliasStoreContract;
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
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

final class PgVectorStore implements AliasStoreContract, VectorStoreContract
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
                PgVectorSearchOptions::apply($this->connection(), PgVectorSearchOptions::forSearch($this->config, $query->options, $query->filter !== null && $query->filter->conditions !== []));
                PgVectorSearchOptions::raiseEfSearch($this->connection(), $query->options, $query->keywords === null ? $query->topK : max($query->topK, $query->depth));

                $collection = $this->registry->resolve($query->collection);
                $this->assertVectorDimensions($query->vector, $collection['dimensions'], $query->collection);

                return $query->keywords === null
                    ? $this->denseSearch($query, $collection)
                    : $this->hybridSearch($query, $collection);
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
            StoreCapability::Keyword,
            StoreCapability::Aliases,
        );
    }

    /**
     * The candidates come from the index in distance order (relaxed with an iterative scan), then are sorted by
     * distance and id.
     *
     * @param  array{metric: PgVectorMetric, storage_identifier: string}  $collection
     */
    private function denseSearch(SearchQuery $query, array $collection): SearchResult
    {
        $metric = $collection['metric'];
        $distance = "embedding {$metric->distanceOperator()} ?::vector";
        $vector = PgVectorVectorFormatter::format($query->vector);
        $filter = $this->filterTranslator->translate($query->filter);
        $bindings = [$vector, ...$filter['bindings']];
        $minScore = '';

        if ($query->minScore !== null) {
            $minScore = " AND ({$distance}) <= ?";
            array_push($bindings, $vector, $metric->maxDistanceForMinScore($query->minScore));
        }

        array_push($bindings, $vector, $query->topK);

        $rows = $this->connection()->select(
            "WITH candidates AS MATERIALIZED (
                SELECT id, content, metadata, {$distance} AS distance FROM {$this->storage($collection)}
                WHERE {$filter['sql']}{$minScore} ORDER BY {$distance} LIMIT ?
             )
             SELECT id, content, metadata, {$metric->scoreSqlExpression('distance')} AS score FROM candidates ORDER BY distance, id COLLATE \"C\"",
            $bindings,
        );

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
    }

    /**
     * One statement: up to max(depth, topK) dense and keyword candidates under the same filter, with the
     * vector distance of every candidate. Fusion, minScore and topK follow in PHP.
     *
     * @param  array{name: string, metric: PgVectorMetric, storage_identifier: string, keyword: array{languages: array<string, string>, language_field: string}|null}  $collection
     */
    private function hybridSearch(SearchQuery $query, array $collection): SearchResult
    {
        $keyword = $collection['keyword'] ?? throw new VectorQueryException(
            "Collection [{$query->collection}] has no keyword index, so it cannot run a hybrid search. Migrate it with keyword search: Vector::migrate('{$query->collection}', new CollectionDefinition('{$query->collection}', keyword: true)) or php artisan vector:migrate {$query->collection} --keyword.",
        );

        $this->connection()->statement('SET LOCAL client_min_messages = warning');

        $metric = $collection['metric'];
        $storage = $this->storage($collection);
        $distance = "embedding {$metric->distanceOperator()} ?::vector";
        $vector = PgVectorVectorFormatter::format($query->vector);
        $filter = $this->filterTranslator->translate($query->filter);
        $limit = max($query->depth, $query->topK);
        $configurations = array_values(array_unique([...array_values($keyword['languages']), 'simple']));
        $parse = match ($query->keywordMatch) {
            // Any of the words: the AND of plainto_tsquery turned into an OR (lexemes are quoted, so only operators change).
            SearchQuery::KEYWORD_MATCH_ANY => static fn (string $configuration): string => "replace(plainto_tsquery('{$configuration}', input.text)::text, '&', '|')::tsquery",
            SearchQuery::KEYWORD_MATCH_WEBSEARCH => static fn (string $configuration): string => "websearch_to_tsquery('{$configuration}', input.text)",
            default => throw new VectorQueryException('Keyword match must be one of: '.implode(', ', SearchQuery::KEYWORD_MATCH_MODES).". Got [{$query->keywordMatch}]."),
        };
        $parsed = implode(', ', array_map(static fn (string $configuration): string => $parse($configuration)." AS q_{$configuration}", $configurations));
        $any = implode(' || ', array_map(static fn (string $configuration): string => "q_{$configuration}", $configurations));
        // Each document matches the query parsed in its own configuration; the OR of all of them lets the GIN index prefilter.
        $own = self::languageCase($keyword, static fn (string $configuration): string => "keyword_query.q_{$configuration}");

        $rows = $this->connection()->select(
            "WITH input AS MATERIALIZED (SELECT ?::text AS text),
             keyword_query AS MATERIALIZED (SELECT parsed.*, {$any} AS q_any FROM (SELECT {$parsed} FROM input) parsed),
             dense AS MATERIALIZED (SELECT id, {$distance} AS distance FROM {$storage} WHERE {$filter['sql']} ORDER BY {$distance} LIMIT ?),
             dense_ranked AS (SELECT id, row_number() OVER (ORDER BY distance, id COLLATE \"C\") AS dense_rank FROM dense),
             keyword AS MATERIALIZED (
                SELECT id, ts_rank_cd(keywords, {$own}) AS keyword_score FROM {$storage}, keyword_query
                WHERE keywords @@ keyword_query.q_any AND keywords @@ {$own} AND {$filter['sql']} ORDER BY keyword_score DESC, id COLLATE \"C\" LIMIT ?
             ),
             keyword_ranked AS (SELECT id, keyword_score, row_number() OVER (ORDER BY keyword_score DESC, id COLLATE \"C\") AS keyword_rank FROM keyword),
             candidates AS (SELECT id FROM dense UNION SELECT id FROM keyword)
             SELECT s.id, s.content, s.metadata, s.{$distance} AS distance, d.dense_rank, k.keyword_score, k.keyword_rank
             FROM candidates c JOIN {$storage} s ON s.id = c.id
             LEFT JOIN dense_ranked d ON d.id = c.id LEFT JOIN keyword_ranked k ON k.id = c.id",
            [$query->keywords, $vector, ...$filter['bindings'], $vector, $limit, ...$filter['bindings'], $limit, $vector],
        );

        $byId = [];
        $dense = [];
        $keywordRanks = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;
            $byId[$id] = $row;

            if ($row->dense_rank !== null) {
                $dense[(int) $row->dense_rank] = $id;
            }

            if ($row->keyword_rank !== null) {
                $keywordRanks[(int) $row->keyword_rank] = $id;
            }
        }

        ksort($dense);
        ksort($keywordRanks);
        $maxDistance = $query->minScore !== null ? $metric->maxDistanceForMinScore($query->minScore) : null;
        $hits = [];

        foreach (ReciprocalRankFusion::fuse(array_values($dense), array_values($keywordRanks), $query->fusionK, $query->keywordWeight) as $id => $fused) {
            $row = $byId[(string) $id];

            if ($maxDistance !== null && (float) $row->distance > $maxDistance) {
                continue;
            }

            $score = $metric->distanceToSimilarity((float) $row->distance);
            $hits[] = new SearchHit(
                id: $row->id,
                score: $score,
                content: (string) $row->content,
                metadata: $this->decodeMetadata($row->metadata),
                scores: ['vector' => $score, 'keyword' => $row->keyword_score !== null ? (float) $row->keyword_score : null, 'fused' => $fused],
            );

            if (count($hits) === $query->topK) {
                break;
            }
        }

        return new SearchResult(hits: $hits, count: count($hits));
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

            $this->connection()->transaction(function () use ($collection, $idList): void {
                $storage = $this->storage($this->registry->resolve($collection, forWrite: true));
                $placeholders = implode(', ', array_fill(0, count($idList), '?'));

                $this->connection()->delete(
                    "DELETE FROM {$storage} WHERE id IN ({$placeholders})",
                    array_map(strval(...), $idList),
                );
            });
        });
    }

    public function deleteByFilter(string $collection, NormalizedFilter $filter): void
    {
        if ($filter->conditions === []) {
            throw new VectorQueryException('Delete by filter requires at least one filter condition.');
        }

        $this->run(function () use ($collection, $filter): void {
            $this->preparePgVector();

            $translated = $this->filterTranslator->translate($filter);

            $this->connection()->transaction(function () use ($collection, $translated): void {
                $storage = $this->storage($this->registry->resolve($collection, forWrite: true));

                $this->connection()->delete(
                    "DELETE FROM {$storage} WHERE {$translated['sql']}",
                    $translated['bindings'],
                );
            });
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

            $this->connection()->transaction(function () use ($collection, $rows, $value, &$updated): void {
                $storage = $this->storage($this->registry->resolve($collection, forWrite: true));

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
            $dimensions = $definition->dimensions ?? $this->embeddings->using($definition->embeddings)->dimensions();
            $metric = PgVectorMetric::fromString($this->resolveCollectionMetric($definition));
            $keyword = $definition->keywordSettings();

            if ($dimensions < 1) {
                throw new VectorQueryException("Collection [{$definition->name}] dimensions must be at least 1.");
            }

            if ($this->registry->exists($definition->name)) {
                $existing = $this->registry->resolve($definition->name);
                $existingIndex = $this->existingIndex($this->registry->qualifiedStorageName($existing['storage_identifier']), $existing['index_options']);

                if ($existing['dimensions'] === $dimensions
                    && $existing['metric'] === $metric
                    && ($existingIndex === null || $existingIndex->equals($index))
                    && ($definition->embeddings === null || $definition->embeddings === ($existing['embedding']['provider'] ?? null))
                    && ($definition->keyword === null || $keyword === $existing['keyword'])) {
                    return;
                }

                throw new VectorQueryException(
                    "Collection [{$definition->name}] already exists with a different definition. Change it with Vector::migrate() (php artisan vector:migrate).",
                );
            }

            if ($keyword !== null) {
                $this->assertTextSearchConfigurations($keyword['languages']);
            }

            $embedding = $this->embeddings->record($definition->embeddings, $dimensions);

            $this->connection()->transaction(function () use ($definition, $dimensions, $metric, $index, $embedding, $keyword): void {
                $storageIdentifier = $this->registry->register($definition, $dimensions, $metric, $index->toArray(), $embedding, $keyword);
                $this->createStorage($storageIdentifier, $dimensions, $metric, $index, $keyword);
            });
        });
    }

    public function deleteCollection(string $name): void
    {
        $this->run(function () use ($name): void {
            $this->preparePgVector();

            $this->connection()->transaction(function () use ($name): void {
                $storage = $this->storage($this->registry->resolve($name));
                $this->registry->remove($name);
                $this->connection()->statement("DROP TABLE IF EXISTS {$storage}");
            });
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

    public function describeCollection(string $name): array
    {
        return $this->run(function () use ($name): array {
            $this->preparePgVector();

            $resolved = $this->registry->resolve($name);

            return [
                'collection' => $resolved['name'],
                'dimensions' => $resolved['dimensions'],
                'metric' => $resolved['metric']->value,
                'index' => $this->existingIndex($this->registry->qualifiedStorageName($resolved['storage_identifier']), $resolved['index_options'])?->toArray(),
                'embedding' => $resolved['embedding'],
                'keyword' => $resolved['keyword'],
            ];
        });
    }

    public function listAliases(): array
    {
        return $this->run(function (): array {
            $this->preparePgVector();

            return $this->registry->listAliases();
        });
    }

    public function deleteAlias(string $alias): void
    {
        $this->run(function () use ($alias): void {
            $this->preparePgVector();
            $this->registry->removeAlias($alias);
        });
    }

    public function pointAlias(string $alias, string $collection): void
    {
        $this->run(function () use ($alias, $collection): void {
            $this->preparePgVector();
            $this->registry->pointAlias($alias, $collection);
        });
    }

    public function adoptCollection(string $name, string $physical): void
    {
        $this->run(function () use ($name, $physical): void {
            $this->preparePgVector();
            $this->connection()->transaction(fn () => $this->registry->adopt($name, $physical));
        });
    }

    public function countDocuments(string $collection): int
    {
        return $this->run(function () use ($collection): int {
            $this->preparePgVector();

            return (int) $this->connection()->scalar("SELECT COUNT(*) FROM {$this->storage($this->registry->resolve($collection))}");
        });
    }

    public function migration(string $alias): ?MigrationState
    {
        return $this->run(function () use ($alias): ?MigrationState {
            $this->preparePgVector();

            return $this->registry->migration($alias);
        });
    }

    public function saveMigration(MigrationState $state): void
    {
        $this->run(function () use ($state): void {
            $this->preparePgVector();
            $this->registry->saveMigration($state);
        });
    }

    public function migrateBatch(MigrationState $state, int $limit): MigrationState
    {
        return $this->run(function () use ($state, $limit): MigrationState {
            $this->preparePgVector();

            $target = $this->registry->resolve($state->target);
            $from = $this->storage($this->registry->resolve($state->source));
            $to = $this->storage($target);
            $after = $state->lastId !== null ? ' WHERE id > ?' : '';
            $bindings = $state->lastId !== null ? [$state->lastId, $limit] : [$limit];

            // The keyword index is built once at catch-up, not row by row during the copy.
            if ($state->lastId === null && $target['keyword'] !== null) {
                $this->connection()->statement("DROP INDEX IF EXISTS {$to}_keyword_idx");
            }

            $next = $this->connection()->transaction(function () use ($state, $limit, $target, $from, $to, $after, $bindings): MigrationState {
                if ($state->mode === 'copy') {
                    /** @var object{count: int|string, last_id: string|null} $page */
                    $page = $this->connection()->selectOne(
                        "WITH page AS (SELECT id, content, embedding, metadata FROM {$from}{$after} ORDER BY id LIMIT ?),
                         copied AS (
                            INSERT INTO {$to} (id, content, embedding, metadata) SELECT id, content, embedding, metadata FROM page
                            ON CONFLICT (id) DO UPDATE SET content = EXCLUDED.content, embedding = EXCLUDED.embedding, metadata = EXCLUDED.metadata
                         )
                         SELECT COUNT(*) AS count, MAX(id) AS last_id FROM page",
                        $bindings,
                    );
                    $count = (int) $page->count;
                    $lastId = $page->last_id;
                } else {
                    $rows = $this->connection()->select("SELECT id, content, metadata FROM {$from}{$after} ORDER BY id LIMIT ?", $bindings);
                    $this->reembedRows($rows, $target);
                    $count = count($rows);
                    $lastId = $rows === [] ? null : (string) $rows[array_key_last($rows)]->id;
                }

                $next = $state->withProgress($state->copied + $count, $lastId ?? $state->lastId);
                $next = $count < $limit ? $next->withPhase('catching_up') : $next;
                $this->registry->saveMigration($next);

                return $next;
            });

            if ($next->phase === 'catching_up') {
                $this->rebuildIfIvfflat($target);
            }

            return $next;
        });
    }

    public function catchUpMigration(MigrationState $state): int
    {
        return $this->run(function () use ($state): int {
            $this->preparePgVector();
            $target = $this->registry->resolve($state->target);

            if ($target['keyword'] !== null) {
                $this->connection()->statement("CREATE INDEX IF NOT EXISTS {$this->storage($target)}_keyword_idx ON {$this->storage($target)} USING gin (keywords)");
            }

            return $this->catchUpPass($state, null);
        });
    }

    public function finishMigration(MigrationState $state, int $threshold): MigrationState
    {
        return $this->run(function () use ($state, $threshold): MigrationState {
            $this->preparePgVector();

            return $this->connection()->transaction(function () use ($state, $threshold): MigrationState {
                // Alias row first (waits for in-flight writes), then the table: writers lock in that order too.
                $this->registry->lockAlias($state->alias);
                $this->connection()->statement("LOCK TABLE {$this->storage($this->registry->resolve($state->source))} IN SHARE ROW EXCLUSIVE MODE");

                $this->catchUpPass($state, $threshold);
                $this->verifyMigration($state);

                $this->registry->pointAlias($state->alias, $state->target);
                $switched = $state->withPhase('switched');
                $this->registry->saveMigration($switched);

                return $switched;
            });
        });
    }

    /**
     * Apply what the source changed since the copy: new ids and changed rows (re-embedded when the content
     * changed in reembed mode), metadata-only changes, deleted ids. Above $threshold it throws first.
     */
    private function catchUpPass(MigrationState $state, ?int $threshold): int
    {
        $target = $this->registry->resolve($state->target);
        $from = $this->storage($this->registry->resolve($state->source));
        $to = $this->storage($target);
        $reembed = $state->mode === 'reembed';

        $changed = $reembed
            ? 't.content IS DISTINCT FROM s.content'
            : '(t.content, t.embedding, t.metadata) IS DISTINCT FROM (s.content, s.embedding, s.metadata)';
        $rewrite = "FROM {$from} s LEFT JOIN {$to} t ON t.id = s.id WHERE t.id IS NULL OR {$changed}";
        $metadataOnly = "FROM {$from} s JOIN {$to} t ON t.id = s.id WHERE t.content = s.content AND t.metadata IS DISTINCT FROM s.metadata";
        $missing = "FROM {$to} t WHERE NOT EXISTS (SELECT 1 FROM {$from} s WHERE s.id = t.id)";

        $difference = (int) $this->connection()->scalar(
            "SELECT (SELECT COUNT(*) {$rewrite}) + (SELECT COUNT(*) {$missing})".($reembed ? " + (SELECT COUNT(*) {$metadataOnly})" : ''),
        );

        if ($threshold !== null && $difference > $threshold) {
            throw new VectorQueryException(
                "Migration of [{$state->alias}] did not switch: {$difference} documents changed since the last pass, above catch_up_threshold {$threshold}. Run it again to continue.",
            );
        }

        if ($difference === 0) {
            return 0;
        }

        if ($reembed) {
            $this->connection()->update("UPDATE {$to} AS t SET metadata = s.metadata FROM {$from} s WHERE s.id = t.id AND t.content = s.content AND t.metadata IS DISTINCT FROM s.metadata");

            foreach (array_chunk($this->connection()->select("SELECT s.id, s.content, s.metadata {$rewrite} ORDER BY s.id"), max(1, $this->batchSize())) as $rows) {
                $this->reembedRows($rows, $target);
            }
        } else {
            $this->connection()->statement(
                "INSERT INTO {$to} (id, content, embedding, metadata) SELECT s.id, s.content, s.embedding, s.metadata {$rewrite}
                 ON CONFLICT (id) DO UPDATE SET content = EXCLUDED.content, embedding = EXCLUDED.embedding, metadata = EXCLUDED.metadata",
            );
        }

        $this->connection()->delete("DELETE FROM {$to} AS t WHERE NOT EXISTS (SELECT 1 FROM {$from} s WHERE s.id = t.id)");

        return $difference;
    }

    private function verifyMigration(MigrationState $state): void
    {
        $from = $this->storage($this->registry->resolve($state->source));
        $to = $this->storage($this->registry->resolve($state->target));

        /** @var object{source: int|string, target: int|string} $counts */
        $counts = $this->connection()->selectOne("SELECT (SELECT COUNT(*) FROM {$from}) AS source, (SELECT COUNT(*) FROM {$to}) AS target");

        if ((int) $counts->source !== (int) $counts->target) {
            throw new VectorQueryException("Migration of [{$state->alias}] did not switch: the source holds {$counts->source} documents, the target {$counts->target}.");
        }

        $same = $state->mode === 'reembed'
            ? 't.content = s.content AND t.metadata = s.metadata'
            : '(t.content, t.embedding, t.metadata) = (s.content, s.embedding, s.metadata)';

        // ponytail: ORDER BY random() sorts the whole source; use TABLESAMPLE if collections reach millions of rows.
        $mismatches = (int) $this->connection()->scalar(
            "SELECT COUNT(*) FROM (SELECT * FROM {$from} ORDER BY random() LIMIT 20) s LEFT JOIN {$to} t ON t.id = s.id WHERE t.id IS NULL OR NOT ({$same})",
        );

        if ($mismatches > 0) {
            throw new VectorQueryException("Migration of [{$state->alias}] did not switch: {$mismatches} sampled documents differ between source and target.");
        }
    }

    /**
     * Embed source rows with the target's recorded provider and upsert them into the target.
     *
     * @param  array<int, object>  $rows  id, content, metadata
     * @param  array{name: string, dimensions: int, storage_identifier: string, embedding: array{provider: string, model: string, dimensions: int}|null}  $target
     */
    private function reembedRows(array $rows, array $target): void
    {
        if ($rows === []) {
            return;
        }

        $documents = array_map(
            fn (object $row): Document => new Document(id: (string) $row->id, content: (string) $row->content, metadata: $this->decodeMetadata($row->metadata)),
            array_values($rows),
        );

        $this->bulkUpsertDocuments(
            storage: $this->storage($target),
            documents: $this->resolveDocumentsWithEmbeddings($documents, $target['embedding']['provider'] ?? null),
            dimensions: $target['dimensions'],
            collection: $target['name'],
            globalMetadata: [],
        );
    }

    /**
     * @param  array{storage_identifier: string}  $resolved
     */
    private function storage(array $resolved): string
    {
        return $this->registry->qualifiedStorageName($resolved['storage_identifier']);
    }

    /**
     * @param  array{storage_identifier: string, metric: PgVectorMetric, index_options: array{type?: string, options?: array<string, int>}|null}|null  $resolved
     */
    private function rebuildIfIvfflat(?array $resolved): void
    {
        if ($resolved === null) {
            return;
        }

        $storage = $this->storage($resolved);
        $index = $this->existingIndex($storage, $resolved['index_options']);

        if ($index?->type === 'ivfflat') {
            $this->rebuildIvfflatIndex($storage, $resolved['metric'], $index);
        }
    }

    /**
     * @param  array{languages: array<string, string>, language_field: string}|null  $keyword
     */
    private function createStorage(string $storageIdentifier, int $dimensions, PgVectorMetric $metric, PgVectorIndex $index, ?array $keyword = null): void
    {
        $storage = $this->registry->qualifiedStorageName($storageIdentifier);
        $keywords = '';

        // A generated column cannot cast metadata to a text search configuration, so the map is a CASE.
        if ($keyword !== null) {
            $configuration = self::languageCase($keyword, static fn (string $configuration): string => "'{$configuration}'::regconfig");
            $keywords = ",\n                keywords tsvector GENERATED ALWAYS AS (to_tsvector({$configuration}, content)) STORED";
        }

        $this->connection()->statement(
            "CREATE TABLE {$storage} (
                id TEXT PRIMARY KEY,
                content TEXT NOT NULL,
                embedding vector({$dimensions}) NOT NULL,
                metadata JSONB NOT NULL DEFAULT '{}'::jsonb{$keywords}
            )",
        );

        $this->connection()->statement($index->createSql($storage, $metric->indexOps()));

        if ($keyword !== null) {
            $this->connection()->statement("CREATE INDEX {$storage}_keyword_idx ON {$storage} USING gin (keywords)");
        }
    }

    /**
     * CASE over the language field: the map's configuration for its values, `simple` for any other value.
     * Values, configurations and the field are validated by CollectionDefinition::keywordSettings().
     *
     * @param  array{languages: array<string, string>, language_field: string}  $keyword
     * @param  callable(string): string  $then  SQL for one configuration
     */
    private static function languageCase(array $keyword, callable $then): string
    {
        $cases = '';

        foreach ($keyword['languages'] as $value => $configuration) {
            $cases .= "WHEN '{$value}' THEN {$then($configuration)} ";
        }

        return $cases === '' ? $then('simple') : "(CASE metadata->>'{$keyword['language_field']}' {$cases}ELSE {$then('simple')} END)";
    }

    /**
     * Every configuration of a language map must exist in Postgres before it goes into a table definition.
     *
     * @param  array<string, string>  $languages
     */
    private function assertTextSearchConfigurations(array $languages): void
    {
        $wanted = array_values(array_unique($languages));

        if ($wanted === []) {
            return;
        }

        $known = array_column($this->connection()->select(
            'SELECT cfgname FROM pg_catalog.pg_ts_config WHERE cfgname IN ('.implode(', ', array_fill(0, count($wanted), '?')).')',
            $wanted,
        ), 'cfgname');
        $missing = array_values(array_diff($wanted, $known));

        if ($missing !== []) {
            throw new VectorQueryException('Unknown Postgres text search configuration(s) in the language map: '.implode(', ', $missing).'.');
        }
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

            $total = count($documents);
            $processed = 0;
            $resolved = null;

            foreach ($this->chunkDocuments($documents) as $chunk) {
                // Each chunk resolves the alias in its own transaction, so a switch mid-ingest moves the rest.
                $resolved = $this->connection()->transaction(function () use (
                    $chunk,
                    $collection,
                    $globalMetadata,
                    $upsert,
                ): array {
                    $resolved = $this->registry->resolve($collection, forWrite: true);
                    $storage = $this->storage($resolved);
                    $embedded = $this->resolveDocumentsWithEmbeddings($chunk, $resolved['embedding']['provider'] ?? null);

                    if ($upsert) {
                        $this->bulkUpsertDocuments(
                            storage: $storage,
                            documents: $embedded,
                            dimensions: $resolved['dimensions'],
                            collection: $collection,
                            globalMetadata: $globalMetadata,
                        );

                        return $resolved;
                    }

                    $this->bulkInsertDocuments(
                        storage: $storage,
                        documents: $embedded,
                        dimensions: $resolved['dimensions'],
                        collection: $collection,
                    );

                    return $resolved;
                });

                $processed += count($chunk);

                if ($onProgress !== null) {
                    $onProgress($processed, $total);
                }
            }

            $this->rebuildIfIvfflat($resolved);
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
    private function resolveDocumentsWithEmbeddings(array $documents, ?string $provider = null): array
    {
        $needsEmbedding = array_values(array_filter(
            $documents,
            static fn (Document $document): bool => $document->vector === null,
        ));

        if ($needsEmbedding === []) {
            return $documents;
        }

        $vectors = TraceSpan::embedding(fn (): array => $this->embeddings->using($provider)->embedBatch(array_map(
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
