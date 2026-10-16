---
name: agenticgraph-vector
description: "Use AgenticGraph vector search: named stores and configuration, the memory store for tests, ingestion and batch ingestion, metadata updates, retrieval, filters and SearchQuery, listing ids, building RAG nodes, score handling, capability discovery, the store conformance suite, vector span attributes, and vector exceptions."
---

# AgenticGraph Vector

docs-sha: 301ce71

Condensed from the AgenticGraph docs (`/docs/vector-...` paths below) at that commit. When a detail here and the package source disagree, the source wins.

Other skills, use them when installed: `agenticgraph-core` for graphs, nodes and state, if `shahzad/agenticgraph` is installed. `agenticgraph-tracing` for the trace a `vector` span lands in.

## What it is

- `shahzad/agenticgraph-vector` stores documents, embeds text, and returns similar hits. It retrieves. It does not prompt, generate, rerank or cite.
- Vectors live on a dedicated pgvector Postgres connection. Your primary database stays where it is; join hits back to it by document `id` or metadata.
- It runs alone. Inside an AgenticGraph node the same call can also become a `vector` span.
- Namespace: `AgenticGraph\Vector`. Facades: `Vector` and `Embeddings`.

Docs: `/docs/vector-overview`

## Install

```bash
composer require shahzad/agenticgraph-vector
php artisan vendor:publish --tag=vector-config
```

```dotenv
VECTOR_DB_CONNECTION=pgvector
OPENAI_API_KEY=sk-...
```

- Add a Postgres connection for vectors in `config/database.php` (the name `pgvector` is a convention), then point `VECTOR_DB_CONNECTION` at it. The default is `pgsql`, which is often your app database.
- `Vector::store('pgvector')` names the *store* in `config/vector.php`. `VECTOR_DB_CONNECTION` names the *database connection* that store uses. They can share a name; they are different settings.
- The store runs `CREATE EXTENSION vector` on first use (`install_extension`, default `true`). Managed Postgres without superuser rights fails with `VectorExtensionMissingException`: enable pgvector in the provider, then set `install_extension` to `false`.
- Verify with `Vector::store('pgvector')->healthCheck()`, which returns `HealthCheckResult(healthy, latencyMs)`.

Docs: `/docs/vector-installation`

## Configuration

`config/vector.php`:

| Key | Default | Notes |
|---|---|---|
| `default` | `pgvector` | Store used by `Vector::store()`. `VECTOR_STORE` |
| `stores.<name>.driver` | | Each named store names its driver: `pgvector`, `memory`, or one from `Vector::extend()` |
| `stores.pgvector.connection` | `pgsql` | `VECTOR_DB_CONNECTION` |
| `stores.pgvector.index` | `hnsw` | Or `ivfflat` (`ivfflat_lists`, 100). Applied at create when the definition sets no index. |
| `stores.pgvector.verify_extension` | `true` | Extension check once per process. `VECTOR_PGVECTOR_VERIFY_EXTENSION` |
| `stores.pgvector.metric` | `cosine` | Or `l2`, `inner_product`. Frozen at create. |
| `stores.pgvector.batch_size` | `100` | Wins over `batch.size` |
| `stores.pgvector.registry_table` | `vector_collection_registry` | Name, dimensions, metric per collection |
| `embeddings.default` | `prism` | Prism. No vendor default: set `VECTOR_EMBEDDINGS_PRISM_PROVIDER` and `VECTOR_EMBEDDINGS_MODEL` (example: `openai`, `text-embedding-3-small`), or the first embedding call throws. `dimensions` null (unset) asks the provider: one extra embed call per process when a collection is created without dimensions. Set `VECTOR_EMBEDDINGS_DIMENSIONS` to skip it. `api_key` null means Prism's provider key (e.g. `OPENAI_API_KEY`). |
| `embeddings.providers.deterministic` | 8 dims | Local hashes for tests. Never tune scores on it. |
| `events.enabled` | `true` | `VectorOperationCompleted` / `VectorOperationFailed` / `VectorEmbeddingCompleted` |

- `stores.memory` (driver `memory`) is a reference store in plain PHP: exact search, pgvector's score math and capabilities, data for the life of the process. For app tests: `VECTOR_STORE=memory` with `VECTOR_EMBEDDINGS_PROVIDER=deterministic`. Not for production.
- The managers keep the first resolved store and provider, including on Octane and in long-lived workers. Static config is fine. After `config([...])` at runtime, reset them: [references/ingestion.md](references/ingestion.md#runtime-config).

Docs: `/docs/vector-configuration`, `/docs/vector-limitations`

## Collections

```php
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;

Vector::store('pgvector')->createCollection(
    new CollectionDefinition(name: 'articles', dimensions: 1536, metric: 'cosine'),
);

Vector::store('pgvector')->collectionExists('articles');
Vector::store('pgvector')->listCollections();
Vector::store('pgvector')->deleteCollection('articles');
```

- Omit `dimensions` to use the embedding provider's width. With `VECTOR_EMBEDDINGS_DIMENSIONS` unset, that width costs one extra embed call per process.
- Index (pgvector): `new CollectionDefinition(name: 'articles', dimensions: 1536, index: 'hnsw', indexOptions: ['m' => 16, 'ef_construction' => 64])`, or `index: 'ivfflat'` with `['lists' => 200]`. Invalid options throw `VectorQueryException` before anything is created. IVFFlat is rebuilt after every write with `min(lists, row count)`. The memory store ignores index options.
- Dimensions, metric and index are frozen. Creating the same name with the same definition does nothing; a different one throws `VectorQueryException`. To change them: new collection, re-upsert, swap callers, drop the old one.

Docs: `/docs/vector-quick-start`, `/docs/vector-reference-facades-and-builder`

## Ingestion

```php
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\ValueObjects\Document;

Vector::store('pgvector')
    ->collection('articles')
    ->onProgress(fn (int $done, int $total) => logger()->info("{$done}/{$total}"))
    ->upsert([
        Document::make('doc-1', 'Laravel queues run jobs in the background.', ['lang' => 'en']),
        Document::make('doc-2', 'Horizon monitors Redis queues.', ['lang' => 'en']),
    ], globalMetadata: ['source' => 'docs-import']);
```

- `upsert()` inserts or replaces by id (an id repeated in one call keeps its last copy); `insert()` throws `VectorDuplicateDocumentException` on an id the collection holds or one repeated in the call.
- Documents without a `vector` are embedded in chunks of `batchSize()`, one `embedBatch()` call per chunk. `onProgress($done, $total)` fires after each chunk.
- A precomputed `vector` on a `Document` skips embedding for that row. It still counts toward progress.
- Metadata must be JSON-safe: strings, numbers, bools, arrays of scalars. `DateTimeInterface` values are stored as UTC ISO 8601 strings (`Y-m-d\TH:i:s\Z`). Otherwise `VectorInvalidMetadataException`.
- Metadata-only changes: `->updateMetadata(['doc-1' => ['last_updated' => now()]])` (one payload per id, shallow merge; `merge: false` replaces). No re-embedding. Returns `UpdateMetadataResult` with `updated` and `unknown` ids. Prefer it to `upsert()` when only metadata changed.
- Do not call `embed()` per document in a loop; upsert already batches.
- Store-level form for seeders: `Vector::store('pgvector')->upsert('articles', $documents)`.
- More: [references/ingestion.md](references/ingestion.md).

Docs: `/docs/vector-guide-batch-ingestion`, `/docs/vector-quick-start`

## Retrieval

```php
$results = Vector::store('pgvector')
    ->collection('articles')
    ->where('lang', 'en')
    ->whereIn('status', ['published', 'featured'])
    ->similarTo('How do queue workers use Redis?')
    ->take(5)
    ->minScore(0.7)
    ->get();

$context = $results->toContext("\n\n---\n\n");
$ids = $results->pluck('id');
```

- `similarTo($text)` embeds the query; `usingVector($vector)` takes a precomputed one. Setting one clears the other. `get()` without either throws `VectorQueryException`.
- Filters on top-level metadata keys: `where('lang', 'en')`, `where('year', '>=', 2020)` (`=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, or `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `contains`), `whereIn()`, `whereNotIn()`, `whereContains('tags', 'x')`. `take()` defaults to 10.
- `ne` and `whereNotIn()` also match documents without the field. `whereContains()` matches array fields only. Equality is type-exact (`'5'` does not match a stored `5`); another type never matches and never errors. Ranges take numbers, or dates as `DateTimeInterface` or `Y-m-d\TH:i:s\Z` strings; another string throws `VectorInvalidFilterException`.
- `compile()` returns the `SearchQuery` (collection, vector, filter, topK, minScore, options) without running it. `options()` is for store-specific tuning only.
- The builder is immutable: each call returns a new instance.
- `SearchResult`: `hits` (`SearchHit`: `id`, `score`, `content`, `metadata`), `count()`, `first()`, `pluck()`, `toContext()`, `filterByScore()`, `averageScore()`, `merge()`, `unique()`, `toArray()`.
- `toContext()` concatenates hit content (default separator `"\n---\n"`). It is not an answer.
- `fetch($ids)` loads by id. Its scores are always `1.0`, not similarity.

Docs: `/docs/vector-reference-facades-and-builder`, `/docs/vector-reference-value-objects`, `/docs/vector-quick-start`

## Scores

| Metric | Score | Range |
|---|---|---|
| `cosine` (default) | `1 - distance` | [-1, 1] |
| `l2` | `1 / (1 + distance)` | (0, 1] |
| `inner_product` | `-distance` | unbounded |

- Cosine scores are not percentages. On Prism OpenAI embeddings strong matches sit near `0.8` and noise near `0.2`. Start at `minScore(0.7)` and tune on your corpus.
- `minScore()` filters in SQL. `filterByScore()` filters in PHP after `get()`; use it for experiments.
- Re-tune when you change the metric or the embedding model. Thresholds do not carry across metrics.
- Ignore scores from `fetch()` and from the `deterministic` provider.
- Without a floor in production, unrelated queries still return the noise band.

Docs: `/docs/vector-guide-score-ergonomics`

## Building RAG

Retrieve, format context, generate with your own LLM call, cite from state. The package only does the first step.

- In a controller: search, `toContext()`, then Prism `->asText()` and read `$response->text`. `generate()` is deprecated.
- In a graph: a retrieve node (`AbstractNode`) writes `context`, `retrieved_ids` and `retrieved_scores` to state; a generate node (`PrismNode::prompt()`) answers; a cite node formats from state. Register the graph class under `config('agenticgraph.graphs')` and run it by name.
- Retrieval in a controller before the graph fires the event but produces no span. If the graph still has a retrieve node, it searches again.
- Examples: [references/rag.md](references/rag.md). Graph and node details: use the `agenticgraph-core` skill.

Docs: `/docs/vector-guide-building-rag`

## Capabilities and health

```php
use AgenticGraph\Vector\Enums\StoreCapability;

$capabilities = Vector::store('pgvector')->capabilities();

if ($capabilities->supports(StoreCapability::DeleteByFilter)) {
    Vector::store('pgvector')->collection('articles')->where('category', 'legal')->delete();
}
```

- `capabilities()` and `healthCheck()` are on the store, not on the `collection()` builder. `capabilities()` returns `StoreCapabilities`; ask it with `supports(StoreCapability::...)`.
- pgvector and memory support `ListIds`, `UpdateMetadata`, `DeleteByFilter`, `FilterNot` (`ne`, `not_in`), `FilterContains` and `FilterRange`. `Keyword` and `Aliases` are not built. An unsupported capability is not a roadmap.
- The builder checks the capability before each operation and throws `VectorUnsupportedOperationException`.
- `healthCheck()` is connectivity, not feature detection.

Docs: `/docs/vector-guide-capability-discovery`

## Deleting

- `delete('doc-1')`, `delete(['doc-1', 'doc-2'])`, builder filters then `delete()`, or `delete(filter: ['lang' => 'fr'])`.
- `deleteByFilter(NormalizedFilter::fromAssociative([...]))` takes a filter object.
- A delete with no ids and no filters, or with ids and a filter together, throws `VectorQueryException`.

## Listing ids

```php
$cursor = null;
do {
    $page = Vector::store('pgvector')->collection('articles')->where('lang', 'en')->listIds(cursor: $cursor, limit: 500);
    // $page->ids
    $cursor = $page->nextCursor;
} while ($cursor !== null);
```

- `IdPage`: `ids` (strings) and `nextCursor` (null on the last page). The cursor is opaque. Order is the store's; every id once.

Docs: `/docs/vector-reference-facades-and-builder`

## Embeddings

```php
use AgenticGraph\Vector\Facades\Embeddings;

$provider = Embeddings::using('prism');   // or using() for the default
$vector = $provider->embed('Single text');
$vectors = $provider->embedBatch(['First chunk', 'Second chunk']);
$provider->dimensions();
$provider->model();
```

- Custom drivers: `Vector::extend('driver', fn ($app, array $config, string $name) => ...)` with a `vector.stores` entry whose `driver` is that name, and `Embeddings::extend('name', fn () => ...)`. `Vector::store()` wraps every store, so its operations get events and spans.
- A custom store proves its behavior with the shipped suite: `use AgenticGraph\Vector\Testing\StoreConformanceTests;` in a Laravel or Testbench test case, with `makeStore()` returning the store. Needs PHPUnit or Pest in dev dependencies.
- Tests use `vector.embeddings.default = deterministic` (8 dimensions, no API calls).

Docs: `/docs/vector-reference-facades-and-builder`, `/docs/vector-configuration`

## Events and vector spans

Two separate channels.

- **Events** (`AgenticGraph\Vector\Events`): `VectorOperationCompleted` (`operation`, `store`, `collection`, `durationMs`, `resultCount`, `documentCount`, `embeddingMs`) and `VectorOperationFailed` (adds `exception`). One per store operation, collection management and store-level calls included, when `events.enabled` is on: controller, job or node, with or without AgenticGraph. `store` is the store name.
- `VectorEmbeddingCompleted` (`model`, `inputCount`, `tokens`, `durationMs`): one per `embed()` / `embedBatch()` of the built-in providers; `tokens` is the provider's reported usage (null for deterministic). Use it for embedding cost.
- **Spans**: a `vector` span named `vector.{operation}` exists only when the call runs inside an AgenticGraph node and AgenticGraph tracing is on. It nests under the node.
- Span attributes (schema v2) are counts and timings, never query text or document bodies: `vector.store`, `vector.collection`, `vector.operation`, `vector.top_k`, `vector.result_count`, `vector.latency_ms`, `vector.embedding_ms`, `vector.score_min`, `vector.score_max`, `vector.filter_count`.
- A `similarTo()` search embeds inside its span: `vector.embedding_ms` (event `embeddingMs`) is that part of the latency, and an embedding failure fires `VectorOperationFailed` for the search. Upsert and insert put the document embedding time there too.
- Without AgenticGraph installed, search runs and events fire with no span overhead.
- Turning tracing on, drivers, and seeing the span in tests: use the `agenticgraph-tracing` skill.

Docs: `/docs/vector-guide-tracing-integration`

## Exceptions

All extend `VectorException` (`AgenticGraph\Vector\Exceptions`).

| Exception | Usual cause |
|---|---|
| `VectorQueryException` | No `similarTo`/`usingVector`, delete without ids or filters or with both, collection redefined, invalid index options |
| `VectorDuplicateDocumentException` | `insert()` with an existing or repeated id (a `VectorQueryException`) |
| `VectorDimensionMismatchException` | Vector width differs from the collection's dimensions |
| `VectorExtensionMissingException` | pgvector not installed and cannot be created |
| `VectorEmbeddingException` | Embedding provider call failed (key, Prism config) |
| `VectorInvalidMetadataException` | Non-scalar metadata |
| `VectorInvalidFilterException` | Nested key, non-scalar or mixed-type value, unknown operator, range on a non-ISO string |
| `VectorUnsupportedOperationException` | Store lacks the feature; check `capabilities()` |
| `VectorCollectionNotFoundException` | Collection not created |
| `VectorConnectionException` | Unexpected database error |
| `VectorNotImplementedException` | Driver method not implemented |

Docs: `/docs/vector-reference-exceptions`

## Limitations

- No in-place collection migration. Dimensions, metric and index need a new collection.
- No hybrid search, reranking, aliases or tenant isolation yet. Named stores exist; there is no routing between them.
- No metadata index: a selective filter can return fewer than `take()` hits.
- Scores are not probabilities.
- Runtime config needs the manager reset (see Configuration).

Docs: `/docs/vector-limitations`
