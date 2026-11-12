<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores;

use AgenticGraph\Vector\Concerns\InteractsWithBuilder;
use AgenticGraph\Vector\Contracts\AliasStoreContract;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\FieldType;
use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\Enums\Metric;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorConnectionException;
use AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException;
use AgenticGraph\Vector\Exceptions\VectorDuplicateDocumentException;
use AgenticGraph\Vector\Exceptions\VectorException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\FilterCondition;
use AgenticGraph\Vector\ValueObjects\HealthCheckResult;
use AgenticGraph\Vector\ValueObjects\IdPage;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;
use AgenticGraph\Vector\ValueObjects\UpdateMetadataResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Qdrant v1.20+ over its REST API (Laravel HTTP client, no retry).
 *
 * A point holds the payload `id` (the document id), `content` and `metadata`; its point id is a UUID v5 of the
 * document id. The collection's own metadata key `agenticgraph` is the registry: dimensions, metric, embedding
 * record and the declared filterable fields, each with a payload index made at create. Filters run on declared
 * fields only: the registry fixes the declared fields and their types at create.
 *
 * - `index`: null or `hnsw`. `indexOptions`: `m` and `ef_construct`, integers.
 * - `SearchQuery::$options`: `exact` (bool) and `hnsw_ef` (int). Any other key throws VectorQueryException.
 * - No keyword search, no migrations. A new filterable field needs a new collection and an alias switch.
 * - insert() checks, then writes in insert_only mode: in a race the existing point is kept and the duplicate is not reported.
 * - `collection_prefix` (config): prepended to every collection and alias name this store creates or reads; the lists
 *   return only names with the prefix, without it. Stores with the same url and prefix share their collections.
 */
final class QdrantStore implements AliasStoreContract, VectorStoreContract
{
    use InteractsWithBuilder;

    /** Namespace of the point ids. Never change it: every stored point id derives from it. */
    public const POINT_NAMESPACE = '8546b9af-95e7-417a-aaa3-5b50c62cd713';

    private const REGISTRY = 'agenticgraph';

    private const DISTANCES = ['cosine' => 'Cosine', 'inner_product' => 'Dot', 'l2' => 'Euclid'];

    private const INDEX_TYPES = [
        'keyword' => 'keyword', 'integer' => 'integer', 'float' => 'float',
        'bool' => 'bool', 'datetime' => 'datetime', 'keyword_list' => 'keyword',
    ];

    /** @var array<string, array{collection: string, dimensions: int, metric: string, embedding: array{provider: string, model: string, dimensions: int}|null, filterable: array<string, string>, hnsw: array<string, mixed>}> */
    private array $registries = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly EmbeddingsManager $embeddings,
        private readonly MetadataValidator $metadataValidator,
        private readonly string $name = 'qdrant',
    ) {}

    public function capabilities(): StoreCapabilities
    {
        return new StoreCapabilities(
            StoreCapability::ListIds,
            StoreCapability::UpdateMetadata,
            StoreCapability::DeleteByFilter,
            StoreCapability::FilterNot,
            StoreCapability::FilterContains,
            StoreCapability::FilterRange,
            StoreCapability::Aliases,
        );
    }

    public function search(SearchQuery $query): SearchResult
    {
        if ($query->keywords !== null) {
            throw new VectorUnsupportedOperationException("Store [{$this->name}] has no keyword search, so it cannot run a hybrid search.");
        }

        $registry = $this->registry($query->collection);
        $this->assertDimensions($query->vector, $registry['dimensions'], $query->collection);
        $metric = Metric::from($registry['metric']);
        $params = $this->searchParams($query->options);

        if ($query->topK < 1) {
            return new SearchResult(hits: [], count: 0);
        }

        $body = ['query' => array_map(floatval(...), $query->vector), 'limit' => $query->topK, 'with_payload' => true];

        if ($query->filter !== null && $query->filter->conditions !== []) {
            $body['filter'] = $this->filter($query->collection, $query->filter);
        }

        // Qdrant's threshold is strict (score > t; Euclid: distance < t) on f32 scores. The contract is inclusive,
        // so the driver sends the f32 just outside the bound. Euclid scores are distances: the bound is an upper one.
        if ($query->minScore !== null) {
            $body['score_threshold'] = $metric === Metric::L2
                ? self::f32Outside($metric->maxDistanceForMinScore($query->minScore), above: true)
                : self::f32Outside($query->minScore, above: false);
        }

        if ($params !== []) {
            $body['params'] = $params;
        }

        $hits = array_map(
            fn (array $point): SearchHit => $this->hit($point, $metric === Metric::L2 ? $metric->distanceToSimilarity((float) $point['score']) : (float) $point['score']),
            $this->request('POST', $this->path($query->collection, 'points/query'), $body)['points'],
        );
        usort($hits, static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score ?: strcmp($a->id, $b->id));

        return new SearchResult(hits: $hits, count: count($hits));
    }

    public function fetch(string $collection, array $ids): SearchResult
    {
        $ids = array_values(array_unique(array_map(strval(...), $ids)));

        if ($ids === []) {
            return new SearchResult(hits: [], count: 0);
        }

        $found = [];

        foreach ($this->request('POST', $this->path($collection, 'points'), ['ids' => array_map(self::pointId(...), $ids), 'with_payload' => true]) as $point) {
            $found[(string) $point['payload']['id']] = $point;
        }

        $hits = array_values(array_map(fn (string $id): SearchHit => $this->hit($found[$id], 1.0), array_filter($ids, static fn (string $id): bool => isset($found[$id]))));

        return new SearchResult(hits: $hits, count: count($hits));
    }

    public function insert(string $collection, array $documents, ?callable $onProgress = null): void
    {
        $this->write($collection, $documents, [], true, $onProgress);
    }

    public function upsert(string $collection, array $documents, array $globalMetadata = [], ?callable $onProgress = null): void
    {
        $this->write($collection, $documents, $globalMetadata, false, $onProgress);
    }

    public function delete(string $collection, array|string|int $ids): void
    {
        $ids = is_array($ids) ? $ids : [$ids];

        if ($ids !== []) {
            $this->request('POST', $this->path($collection, 'points/delete?wait=true'), ['points' => array_map(static fn (string|int $id): string => self::pointId((string) $id), $ids)]);
        }
    }

    public function deleteByFilter(string $collection, NormalizedFilter $filter): void
    {
        if ($filter->conditions === []) {
            throw new VectorQueryException('Delete by filter requires at least one filter condition.');
        }

        $this->request('POST', $this->path($collection, 'points/delete?wait=true'), ['filter' => $this->filter($collection, $filter)]);
    }

    public function listIds(string $collection, ?NormalizedFilter $filter = null, ?string $cursor = null, int $limit = 100): IdPage
    {
        $body = ['limit' => $limit + 1, 'with_payload' => ['id'], 'with_vector' => false];

        if ($filter !== null && $filter->conditions !== []) {
            $body['filter'] = $this->filter($collection, $filter);
        }

        if ($cursor !== null) {
            $body['offset'] = $cursor;
        }

        $points = $this->request('POST', $this->path($collection, 'points/scroll'), $body)['points'];
        $ids = array_map(static fn (array $point): string => (string) $point['payload']['id'], $points);

        // The cursor is the point id of the first point of the next page (Qdrant's scroll offset).
        return count($points) > $limit
            ? new IdPage(array_slice($ids, 0, $limit), (string) $points[$limit]['id'])
            : new IdPage($ids);
    }

    public function updateMetadata(string $collection, array $metadataById, bool $merge = true): UpdateMetadataResult
    {
        $filterable = $this->fieldTypes($collection);
        $changes = [];

        foreach ($metadataById as $id => $metadata) {
            $changes[(string) $id] = CollectionDefinition::assertDeclaredTypes($filterable, $this->metadataValidator->normalize($metadata), $id);
        }

        if ($changes === []) {
            return new UpdateMetadataResult(updated: [], unknown: []);
        }

        // Qdrant answers 404 for the whole call when one id is unknown, so the known ids are found first.
        $ids = array_map(strval(...), array_keys($changes));
        $found = [];

        foreach ($this->request('POST', $this->path($collection, 'points'), ['ids' => array_map(self::pointId(...), $ids), 'with_payload' => ['id']]) as $point) {
            $found[(string) $point['payload']['id']] = true;
        }

        $updated = array_values(array_filter($ids, static fn (string $id): bool => isset($found[$id])));
        $operations = [];

        foreach ($updated as $id) {
            $metadata = (object) $changes[$id];

            if ($merge && $changes[$id] !== []) {
                $operations[] = ['set_payload' => ['payload' => $metadata, 'key' => 'metadata', 'points' => [self::pointId($id)]]];
            } elseif (! $merge) {
                $operations[] = ['set_payload' => ['payload' => ['metadata' => $metadata], 'points' => [self::pointId($id)]]];
            }
        }

        if ($operations !== []) {
            $this->request('POST', $this->path($collection, 'points/batch?wait=true'), ['operations' => $operations]);
        }

        return new UpdateMetadataResult(updated: $updated, unknown: array_values(array_diff($ids, $updated)));
    }

    public function batchSize(): int
    {
        return max(1, (int) ($this->config['batch_size'] ?? config('vector.batch.size', 100)));
    }

    public function createCollection(CollectionDefinition $definition): void
    {
        if ($definition->keyword === true) {
            throw new VectorUnsupportedOperationException("Store [{$this->name}] has no keyword search. Create collection [{$definition->name}] without keyword: true.");
        }

        $metricName = $definition->metric ?? (string) ($this->config['metric'] ?? 'cosine');
        $metric = Metric::tryFrom($metricName) ?? throw new VectorQueryException(
            "Metric [{$metricName}] is not supported by the qdrant store. Use one of: cosine, l2, inner_product.",
        );
        $hnsw = $this->hnswConfig($definition);
        $filterable = $definition->filterableFields();
        $dimensions = $definition->dimensions ?? $this->embeddings->using($definition->embeddings)->dimensions();

        if ($dimensions < 1) {
            throw new VectorQueryException("Collection [{$definition->name}] dimensions must be at least 1.");
        }

        $this->registries = [];
        $existing = null;

        try {
            $existing = $this->registry($definition->name);
        } catch (VectorCollectionNotFoundException) {
        }

        if ($existing !== null) {
            if ($existing['dimensions'] === $dimensions
                && $existing['metric'] === $metric->value
                && ($definition->embeddings === null || $definition->embeddings === ($existing['embedding']['provider'] ?? null))
                && ($definition->filterable === null || array_map(static fn (FieldType $type): string => $type->value, $filterable) === $existing['filterable'])
                && array_intersect_key($existing['hnsw'], $hnsw) == $hnsw) {
                return;
            }

            throw new VectorQueryException(
                "Collection [{$definition->name}] already exists with a different definition. Store [{$this->name}] has no migrations: create a new collection with the new definition, copy the documents into it, and switch an alias to it with pointAlias().",
            );
        }

        $registry = [
            'format' => 1,
            'collection' => $definition->name,
            'dimensions' => $dimensions,
            'metric' => $metric->value,
            'embedding' => $this->embeddings->record($definition->embeddings, $dimensions),
            'filterable' => (object) array_map(static fn (FieldType $type): string => $type->value, $filterable),
        ];

        $this->request('PUT', $this->path($definition->name), array_filter([
            'vectors' => ['size' => $dimensions, 'distance' => self::DISTANCES[$metric->value]],
            'hnsw_config' => $hnsw === [] ? null : $hnsw,
            'metadata' => [self::REGISTRY => $registry],
        ]));

        // Payload indexes before any write, so the planner can use them from the first point.
        try {
            $this->request('PUT', $this->path($definition->name, 'index?wait=true'), ['field_name' => 'id', 'field_schema' => 'keyword']);

            foreach ($filterable as $field => $type) {
                $this->request('PUT', $this->path($definition->name, 'index?wait=true'), ['field_name' => "metadata.{$field}", 'field_schema' => self::INDEX_TYPES[$type->value]]);
            }
        } catch (VectorException $exception) {
            try {
                $this->request('DELETE', $this->path($definition->name));
            } catch (Throwable) {
                // The index error below is the one to report.
            }

            throw $exception;
        }
    }

    public function deleteCollection(string $name): void
    {
        $aliases = $this->listAliases();

        if (isset($aliases[$name])) {
            throw new VectorQueryException("Collection [{$name}] is an alias. Delete the alias with deleteAlias(), or delete its collection [{$aliases[$name]}].");
        }

        $alias = array_search($name, $aliases, true);

        if ($alias !== false) {
            throw new VectorQueryException("Collection [{$name}] is the target of alias [{$alias}]. Delete the alias first.");
        }

        $this->registries = [];

        // Qdrant answers false, not 404, for a name it does not hold.
        if ($this->request('DELETE', $this->path($name)) !== true) {
            throw new VectorCollectionNotFoundException("Collection [{$name}] was not found.");
        }
    }

    public function collectionExists(string $name): bool
    {
        return ($this->request('GET', $this->path($name, 'exists'))['exists'] ?? false) === true;
    }

    public function listCollections(): array
    {
        $names = [];

        foreach ($this->request('GET', 'collections')['collections'] as $collection) {
            if (($name = $this->unprefixed((string) $collection['name'])) !== null) {
                $names[] = $name;
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }

    public function healthCheck(): HealthCheckResult
    {
        $started = hrtime(true);
        $this->request('GET', 'readyz');

        return new HealthCheckResult(healthy: true, latencyMs: (int) round((hrtime(true) - $started) / 1_000_000));
    }

    public function describeCollection(string $name): array
    {
        $registry = $this->registry($name);

        return [
            'collection' => $registry['collection'],
            'dimensions' => $registry['dimensions'],
            'metric' => $registry['metric'],
            'index' => ['type' => 'hnsw', 'options' => array_intersect_key($registry['hnsw'], ['m' => 0, 'ef_construct' => 0])],
            'embedding' => $registry['embedding'],
            'keyword' => null,
            'filterable' => $registry['filterable'],
        ];
    }

    public function listAliases(): array
    {
        $aliases = [];

        foreach ($this->request('GET', 'aliases')['aliases'] as $alias) {
            if (($name = $this->unprefixed((string) $alias['alias_name'])) !== null) {
                $aliases[$name] = $this->unprefixed((string) $alias['collection_name']) ?? (string) $alias['collection_name'];
            }
        }

        ksort($aliases, SORT_STRING);

        return $aliases;
    }

    public function deleteAlias(string $alias): void
    {
        // Qdrant answers true for an unknown alias.
        if (! isset($this->listAliases()[$alias])) {
            throw new VectorCollectionNotFoundException("Alias [{$alias}] was not found.");
        }

        $this->registries = [];
        $this->request('POST', 'collections/aliases', ['actions' => [['delete_alias' => ['alias_name' => $this->prefix().$alias]]]]);
    }

    /**
     * One create_alias action: Qdrant re-points an existing alias atomically.
     */
    public function pointAlias(string $alias, string $collection): void
    {
        if (in_array($alias, $this->listCollections(), true)) {
            throw new VectorQueryException("[{$alias}] is a collection, not an alias.");
        }

        $this->registries = [];
        $this->request('POST', 'collections/aliases', ['actions' => [['create_alias' => ['collection_name' => $this->prefix().$collection, 'alias_name' => $this->prefix().$alias]]]]);
    }

    public function countDocuments(string $collection): int
    {
        return (int) $this->request('POST', $this->path($collection, 'points/count'), ['exact' => true])['count'];
    }

    /**
     * UUID version 5 (RFC 4122) of the document id in POINT_NAMESPACE.
     */
    public static function pointId(string $id): string
    {
        $hash = sha1((string) hex2bin(str_replace('-', '', self::POINT_NAMESPACE)).$id);
        $hash[12] = '5';
        $hash[16] = dechex((hexdec($hash[16]) & 0x3) | 0x8);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(substr($hash, 0, 32), 4));
    }

    /**
     * The f32 next to $bound on its open side: below it for a lower bound, above it for an upper one. A strict
     * comparison against it keeps exactly the f32 values that meet the bound inclusively.
     */
    private static function f32Outside(float $bound, bool $above): float
    {
        $bits = (int) unpack('V', pack('g', $bound))[1];
        $ordered = $bits >= 0x80000000 ? -($bits & 0x7FFFFFFF) : $bits;
        $nearest = (float) unpack('g', pack('g', $bound))[1];

        // First the f32 closest to the bound on its inclusive side, then one step out.
        if (! $above && $nearest < $bound) {
            $ordered++;
        } elseif ($above && $nearest > $bound) {
            $ordered--;
        }

        $ordered += $above ? 1 : -1;

        return (float) unpack('g', pack('V', $ordered < 0 ? -$ordered | 0x80000000 : $ordered))[1];
    }

    /**
     * @param  list<Document>  $documents
     * @param  array<string, mixed>  $globalMetadata
     * @param  (callable(int, int): void)|null  $onProgress
     */
    private function write(string $collection, array $documents, array $globalMetadata, bool $insert, ?callable $onProgress): void
    {
        $registry = $this->registry($collection);
        $filterable = $this->fieldTypes($collection);
        $total = count($documents);
        $processed = 0;

        foreach (array_chunk($documents, $this->batchSize()) as $chunk) {
            $vectors = $this->embedMissing($chunk, $registry['embedding']['provider'] ?? null);
            $points = [];

            foreach ($chunk as $index => $document) {
                $id = (string) $document->id;
                $vector = $document->vector ?? $vectors[$index] ?? throw new VectorQueryException("Document [{$id}] could not be embedded.");
                $this->assertDimensions($vector, $registry['dimensions'], $collection);

                if ($insert && isset($points[$id])) {
                    throw VectorDuplicateDocumentException::forId($collection, $id);
                }

                $metadata = $this->metadataValidator->normalize($insert ? $document->metadata : array_merge($globalMetadata, $document->metadata));
                $points[$id] = [
                    'id' => self::pointId($id),
                    'vector' => array_map(floatval(...), $vector),
                    'payload' => ['id' => $id, 'content' => $document->content, 'metadata' => (object) CollectionDefinition::assertDeclaredTypes($filterable, $metadata, $id)],
                ];
            }

            if ($insert) {
                // Check, then write in insert_only mode (Q5): in a race the existing point is kept, but insert_only skips it
                // silently, so that duplicate is not reported (O2).
                $existing = $this->request('POST', $this->path($collection, 'points'), ['ids' => array_column($points, 'id'), 'with_payload' => ['id']]);

                if ($existing !== []) {
                    throw VectorDuplicateDocumentException::forId($collection, (string) $existing[0]['payload']['id']);
                }
            }

            $this->request('PUT', $this->path($collection, 'points?wait=true'), ['points' => array_values($points)] + ($insert ? ['update_mode' => 'insert_only'] : []));
            $processed += count($chunk);

            if ($onProgress !== null) {
                $onProgress($processed, $total);
            }
        }
    }

    /**
     * The Qdrant filter of declared fields (B7). A filter on a field that is not declared throws before the request.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function filter(string $collection, NormalizedFilter $filter): array
    {
        $declared = $this->fieldTypes($collection);
        $must = [];
        $mustNot = [];

        foreach ($filter->conditions as $condition) {
            $type = $declared[$condition->field] ?? throw new VectorUnsupportedOperationException(
                "Field [{$condition->field}] is not declared filterable on collection [{$collection}], and store [{$this->name}] filters declared fields only. Declare it in CollectionDefinition::\$filterable.",
            );

            // must_not also keeps points without the field, which is the contract for ne and not_in.
            if (in_array($condition->operator, [FilterOperator::NotEquals, FilterOperator::NotIn], true)) {
                $mustNot[] = $this->condition($type, $condition);
            } else {
                $must[] = $this->condition($type, $condition);
            }
        }

        return array_filter(['must' => $must, 'must_not' => $mustNot]);
    }

    /**
     * The positive condition (ne and not_in give their eq and in form, placed under must_not).
     * Float and datetime equality is a closed range: Qdrant has no float match, and an integer match misses 5.0.
     *
     * @return array<string, mixed>
     */
    private function condition(FieldType $type, FilterCondition $condition): array
    {
        $key = "metadata.{$condition->field}";
        $exact = static fn (mixed $value): array => in_array($type, [FieldType::Float, FieldType::Datetime], true)
            ? ['key' => $key, 'range' => ['gte' => $value, 'lte' => $value]]
            : ['key' => $key, 'match' => ['value' => $value]];

        return match ($condition->operator) {
            FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::Contains => $exact($condition->value),
            FilterOperator::In, FilterOperator::NotIn => $type === FieldType::Float
                ? ['should' => array_map($exact, (array) $condition->value)]
                : ['key' => $key, 'match' => ['any' => $condition->value]],
            default => ['key' => $key, 'range' => [$condition->operator->value => $condition->value]],
        };
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, bool|int>
     */
    private function searchParams(array $options): array
    {
        $params = [];

        foreach ($options as $key => $value) {
            $params[$key] = match (true) {
                $key === 'exact' && is_bool($value) => $value,
                $key === 'hnsw_ef' && is_int($value) && $value > 0 => $value,
                default => throw new VectorQueryException("Search option [{$key}] is not valid for the qdrant store. Use exact (bool) or hnsw_ef (positive int)."),
            };
        }

        return $params;
    }

    /**
     * @return array<string, int>
     */
    private function hnswConfig(CollectionDefinition $definition): array
    {
        if ($definition->index !== null && $definition->index !== 'hnsw') {
            throw new VectorQueryException("Index [{$definition->index}] is not supported by the qdrant store. Use hnsw.");
        }

        $hnsw = [];

        foreach ($definition->indexOptions as $key => $value) {
            if (! in_array($key, ['m', 'ef_construct'], true) || ! is_int($value) || $value < 0) {
                throw new VectorQueryException("Index option [{$key}] is not valid for the qdrant store. Use m and ef_construct, as integers.");
            }

            $hnsw[$key] = $value;
        }

        return $hnsw;
    }

    /**
     * The registry of a collection or alias, cached until a create, delete or alias change through this instance.
     *
     * @return array{collection: string, dimensions: int, metric: string, embedding: array{provider: string, model: string, dimensions: int}|null, filterable: array<string, string>, hnsw: array<string, mixed>}
     */
    private function registry(string $name): array
    {
        if (isset($this->registries[$name])) {
            return $this->registries[$name];
        }

        $config = $this->request('GET', $this->path($name))['config'] ?? [];
        $registry = $config['metadata'][self::REGISTRY] ?? null;

        if (! is_array($registry)) {
            throw new VectorQueryException("Collection [{$name}] was not created by agenticgraph/vector (it has no [agenticgraph] metadata), so store [{$this->name}] cannot use it.");
        }

        $filterable = (array) ($registry['filterable'] ?? []);
        ksort($filterable, SORT_STRING);

        return $this->registries[$name] = [
            'collection' => (string) ($registry['collection'] ?? $name),
            'dimensions' => (int) $registry['dimensions'],
            'metric' => (string) $registry['metric'],
            'embedding' => $registry['embedding'] ?? null,
            'filterable' => $filterable,
            'hnsw' => (array) ($config['hnsw_config'] ?? []),
        ];
    }

    /**
     * @return array<string, FieldType>
     */
    private function fieldTypes(string $collection): array
    {
        return array_map(FieldType::from(...), $this->registry($collection)['filterable']);
    }

    /**
     * @param  array<string, mixed>  $point
     */
    private function hit(array $point, float $score): SearchHit
    {
        $payload = $point['payload'];

        return new SearchHit(id: (string) $payload['id'], score: $score, content: (string) ($payload['content'] ?? ''), metadata: (array) ($payload['metadata'] ?? []));
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
     * @param  list<float>  $vector
     */
    private function assertDimensions(array $vector, int $dimensions, string $collection): void
    {
        if (count($vector) !== $dimensions) {
            throw new VectorDimensionMismatchException("Vector dimensions for collection [{$collection}] must be {$dimensions}, ".count($vector).' given.');
        }
    }

    private function prefix(): string
    {
        return (string) ($this->config['collection_prefix'] ?? '');
    }

    /**
     * The store's name for a Qdrant collection or alias name, or null when the name lacks this store's prefix.
     */
    private function unprefixed(string $name): ?string
    {
        return str_starts_with($name, $this->prefix()) ? substr($name, strlen($this->prefix())) : null;
    }

    private function path(string $collection, string $suffix = ''): string
    {
        return 'collections/'.rawurlencode($this->prefix().$collection).($suffix === '' ? '' : "/{$suffix}");
    }

    /**
     * One REST call; returns the `result` of the answer. JSON keeps 3.0 a float (D2).
     *
     * @param  array<string, mixed>|null  $body
     */
    private function request(string $method, string $path, ?array $body = null): mixed
    {
        $url = rtrim((string) ($this->config['url'] ?? ''), '/');

        if ($url === '') {
            throw new VectorConnectionException("Store [{$this->name}] has no url. Set VECTOR_QDRANT_URL (vector.stores.{$this->name}.url).");
        }

        $request = Http::baseUrl($url)
            ->acceptJson()
            ->timeout((float) ($this->config['timeout'] ?? 10))
            ->connectTimeout((float) ($this->config['connect_timeout'] ?? 3));

        if ((string) ($this->config['api_key'] ?? '') !== '') {
            $request = $request->withHeaders(['api-key' => (string) $this->config['api_key']]);
        }

        if ($body !== null) {
            $request = $request->withBody(json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 'application/json');
        }

        try {
            $response = $request->send($method, $path);
        } catch (ConnectionException $exception) {
            throw new VectorConnectionException("Store [{$this->name}] could not reach Qdrant at {$url}: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->successful()) {
            return $response->json('result');
        }

        $message = "Store [{$this->name}]: ".(string) ($response->json('status.error') ?? $response->body());

        throw match (true) {
            $response->status() === 404 && preg_match('#^collections/([^/?]+)#', $path, $match) === 1 => new VectorCollectionNotFoundException('Collection ['.($this->unprefixed(rawurldecode($match[1])) ?? rawurldecode($match[1])).'] was not found.'),
            $response->status() === 404 => new VectorCollectionNotFoundException($message),
            str_contains($message, 'Vector dimension error') => new VectorDimensionMismatchException($message),
            default => new VectorQueryException($message),
        };
    }
}
