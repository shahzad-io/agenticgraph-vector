---
name: agenticgraph-vector
description: "Use AgenticGraph vector search: named stores and configuration, the memory store for tests, ingestion and batch ingestion, chunking long documents (TextSplitter, upsertChunks(), collapse()), queued ingest (queue()->upsert(), vector:ingest:resume), metadata updates, retrieval, filters and SearchQuery, listing ids, building RAG nodes, score handling, capability discovery, the store conformance suite, aliases and zero-downtime collection migration (vector:migrate), hybrid search (keyword plus vector, hybrid(), keywords()), reranking (rerank(), Vector::reranker(), Cohere, Voyage, an LLM through Prism), HNSW iterative scan, vector span attributes, and vector exceptions."
---

# AgenticGraph Vector

docs-sha: 1e3e4db

Condensed from the AgenticGraph docs (`/docs/vector-...` paths below) at that commit. When a detail here and the package source disagree, the source wins.

Other skills, use them when installed: `agenticgraph-core` for graphs, nodes and state, if `shahzad/agenticgraph` is installed. `agenticgraph-tracing` for the trace a `vector` span lands in.

## Rules

1. Provider, model and driver names live in config. Names in this skill are examples, labelled as such. Nothing here is a default.
2. Visitor text reaches a model only as a user message or as a template variable (`vars()`). Never concatenate it into instructions or a system prompt. Never join or interpolate runtime values (visitor text, model output, retrieved content) into prompt text; pass them through `vars()` or `history()`.
3. `score` is the vector similarity. `minScore()` filters on it, before reranking. Never threshold `scores['rerank']`, `scores['fused']` or `scores['keyword']`.
4. Chunk a document only when it is too long to embed whole.
5. Cite a chunk search by `metadata['parent_id']` and the parent's title or source, never by chunk id.
6. Do not run two queued ingests of the same ids at once.
7. Use the memory store and the `deterministic` provider in tests. Never tune scores on the deterministic provider.
8. Name the reranker in `rerank(using: '<name>')`, a `vector.rerank.providers` entry (example names). Without `using:`, `rerank()` reads `vector.rerank.default`, and throws when that is unset.

## What it is

- `shahzad/agenticgraph-vector` stores documents, embeds text and returns similar hits. It does not prompt, generate or cite. Reranking is an optional step on a search that reorders its hits.
- Vectors live on a dedicated pgvector Postgres connection. Your primary database stays where it is; join hits back to it by document `id` or metadata.
- It runs alone. Inside an AgenticGraph node, the same call can also become a `vector` span.
- Namespace: `AgenticGraph\Vector`. Facades: `Vector` and `Embeddings`.

Docs: `/docs/vector-overview`

## Install and configure

```bash
composer require shahzad/agenticgraph-vector
php artisan vendor:publish --tag=vector-config
```

```dotenv
VECTOR_DB_CONNECTION=pgvector
OPENAI_API_KEY=sk-...   # example: the key your embedding provider needs
```

- Add a Postgres connection for vectors in `config/database.php` (the name `pgvector` is a convention), then point `VECTOR_DB_CONNECTION` at it. The default is `pgsql`, often your app database.
- `Vector::store('pgvector')` names the *store* in `config/vector.php`. `VECTOR_DB_CONNECTION` names the *database connection* that store uses. They can share a name; they are different settings.
- The store runs `CREATE EXTENSION vector` on first use (`install_extension`, default `true`). Managed Postgres without superuser rights fails with `VectorExtensionMissingException`: enable pgvector in the provider, then set `install_extension` to `false`.
- Verify with `Vector::store('pgvector')->healthCheck()`, which returns `HealthCheckResult(healthy, latencyMs)`. It checks connectivity, not features.

Docs: `/docs/vector-installation`

### Config

`config/vector.php`:

| Key | Default | Notes |
|---|---|---|
| `default` | `pgvector` | Store used by `Vector::store()`. `VECTOR_STORE` |
| `stores.<name>.driver` | | Each named store names its driver: `pgvector`, `memory`, or one from `Vector::extend()` |
| `stores.pgvector.connection` | `pgsql` | `VECTOR_DB_CONNECTION` |
| `stores.pgvector.index` | `hnsw` | Or `ivfflat` (`ivfflat_lists`, 100). Applied at create when the definition sets no index. See Index below. |
| `stores.pgvector.verify_extension` | `true` | Extension check once per process. `VECTOR_PGVECTOR_VERIFY_EXTENSION` |
| `stores.pgvector.metric` | `cosine` | Or `l2`, `inner_product`. Frozen at create. |
| `stores.pgvector.batch_size` | `100` | Wins over `batch.size` |
| `stores.pgvector.registry_table` | `vector_collection_registry` | Name, dimensions, metric per collection |
| `embeddings.default` | `prism` | Prism. No vendor default: set `VECTOR_EMBEDDINGS_PRISM_PROVIDER` and `VECTOR_EMBEDDINGS_MODEL` (example: `openai`, `text-embedding-3-small`), or the first embedding call throws. `dimensions` null (unset) asks the provider: one extra embed call per process when a collection is created without dimensions. Set `VECTOR_EMBEDDINGS_DIMENSIONS` to skip it. `api_key` null means Prism's provider key (e.g. `OPENAI_API_KEY`). `url` (`VECTOR_EMBEDDINGS_URL`) sends this entry's requests to another base URL, such as a gateway; the Prism reranker entry has its own `url` (`VECTOR_RERANK_PRISM_URL`). On an HTTP error from a configured url the exception ends with `(configured url: ...)`. Prism entries take `timeout` (30 s), `retries` (2, on 429, 5xx and connection errors only; `Retry-After` honored up to `retry_after_max_ms`, 2000, longer fails the request as retryable) and `retry_sleep_ms` (500, doubled per retry). |
| `embeddings.providers.deterministic` | 8 dims | Local hashes for tests. Never tune scores on it. |
| `events.enabled` | `true` | `VectorOperationCompleted` / `VectorOperationFailed` / `VectorEmbeddingCompleted` / `VectorRerankCompleted` / `VectorRerankFailed` |
| `rerank.default` | none | Reranker used by `rerank()` without a name. `VECTOR_RERANK_PROVIDER`; unset, `rerank()` without `using:` throws. |
| `rerank.fail_open` | `true` | On a reranker error or timeout, return the store order. `VECTOR_RERANK_FAIL_OPEN` |
| `rerank.timeout`, `rerank.retries`, `rerank.retry_sleep_ms` | `5`, `2`, `500` | Total seconds per rerank, retries included (429, 5xx, timeouts), then fail-open. `VECTOR_RERANK_TIMEOUT`, `VECTOR_RERANK_RETRIES`, `VECTOR_RERANK_RETRY_SLEEP_MS` |
| `collapse.overfetch` | `3` | Chunks fetched per requested hit by `collapse()`. `VECTOR_COLLAPSE_OVERFETCH` |
| `queue.connection`, `queue.queue` | none | Queue for `queue()->upsert()`; unset uses the app's default. `VECTOR_QUEUE_CONNECTION`, `VECTOR_QUEUE` |
| `queue.tries`, `queue.backoff`, `queue.timeout` | `3`, `[10, 30, 60]`, `60` | Per batch job. The timeout must be below the connection's `retry_after` and above the Prism provider's `timeout` + 5 s (defaults pass). Inside the job the provider's retries are off; tries and backoff retry the batch. `VECTOR_QUEUE_TRIES`, `VECTOR_QUEUE_TIMEOUT` |

- `stores.memory` (driver `memory`) is a reference store in plain PHP: exact search, pgvector's score math and capabilities, data for the life of the process. For app tests: `VECTOR_STORE=memory` with `VECTOR_EMBEDDINGS_PROVIDER=deterministic`. Not for production.
- The managers keep the first resolved store and provider, including on Octane and in long-lived workers. Static config is fine. After `config([...])` at runtime, reset them: [references/ingestion.md](references/ingestion.md#runtime-config).

Docs: `/docs/vector-configuration`, `/docs/vector-limitations`

## Create collections

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
- Index (pgvector): `new CollectionDefinition(name: 'articles', dimensions: 1536, index: 'hnsw', indexOptions: ['m' => 16, 'ef_construction' => 64])`, or `index: 'ivfflat'` with `['lists' => 200]`. Invalid options throw `VectorQueryException` before anything is created. The memory store ignores index options.
- **IVFFlat is never rebuilt on write.** Upsert does not rebuild it. Run `php artisan vector:index:rebuild {collection}` (`Vector::rebuildIndex()`, non-blocking) after the first bulk load and again when the data has changed a lot. A migration target is rebuilt once when its copy is in.
- Dimensions, metric, index and embeddings are fixed at create. Creating the same name with the same definition does nothing; a different one throws `VectorQueryException`. To change them, migrate (below).
- `new CollectionDefinition(name: 'articles', embeddings: 'prism_large')` names a provider from `vector.embeddings.providers`. The collection records its provider, model and width and embeds documents and `similarTo()` queries with it; a later change of the default does not affect it.

Docs: `/docs/vector-quick-start`, `/docs/vector-reference-facades-and-builder`

## Migrate a collection

Use this for a new embedding model, a new width, metric or index, or a keyword column. Do not drop and recreate a live collection.

```php
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;

$plan = Vector::migrate('articles', new CollectionDefinition('articles', embeddings: 'prism_large'), dryRun: true);
$state = Vector::migrate('articles', new CollectionDefinition('articles', embeddings: 'prism_large'));
Vector::rollbackMigration('articles');
Vector::dropOldCollection('articles');
Vector::store('pgvector')->listAliases();
```

- CLI: `php artisan vector:migrate articles --embeddings=prism_large` (also `--dry-run`, `--copy`, `--reembed`, `--batch`, `--dimensions`, `--metric`, `--index`, `--index-option=m=16`, `--store`, `--rollback`, `--drop-old`).
- The first migration turns `articles` into an alias for `articles__v1`; the new collection is `articles__v2`. Code that uses `articles` keeps working: every store operation resolves aliases.
- Same provider, model and width: vectors are copied in Postgres. Otherwise documents are re-embedded with the new provider. A collection with no recorded model (created before B5) needs `--copy` or `--reembed`.
- Reads and writes stay on the old collection until the switch. Catch-up passes apply writes made during the copy; the final pass pauses writers (not searches) and switches in one transaction. Writers embed before taking any lock; a final pass that finds documents to embed releases the lock for another unlocked pass, and only the last allowed pass embeds inside it. `vector.migration.catch_up_threshold` (default 500), at most `vector.migration.catch_up_max_passes` unlocked passes (default 10).
- Options left out of the migration definition come from the source collection (width, metric, index, index options, provider). `--dry-run` prints the resulting definition.
- A failed or killed run resumes when rerun with the same definition. The old collection stays until `dropOldCollection()` / `--drop-old`.
- Needs the `Aliases` capability (pgvector, memory). `deleteCollection()` on an alias, or on the collection behind one, throws; use `deleteAlias()` first. `deleteAlias()` also removes the alias's migration rows and refuses while its migration is unfinished.

Docs: `/docs/vector-guide-changing-embedding-model`

## Ingest documents

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

- `upsert()` inserts or replaces by id (an id repeated in one call keeps its last copy). `insert()` throws `VectorDuplicateDocumentException` on an id the collection holds or one repeated in the call.
- Documents without a `vector` are embedded in chunks of `batchSize()`, one `embedBatch()` call per chunk. `onProgress($done, $total)` fires after each chunk.
- A precomputed `vector` on a `Document` skips embedding for that row. It still counts toward progress.
- Metadata must be JSON-safe: strings, numbers, bools, arrays of scalars. `DateTimeInterface` values are stored as UTC ISO 8601 strings (`Y-m-d\TH:i:s\Z`). Otherwise `VectorInvalidMetadataException`.
- Metadata-only changes: `->updateMetadata(['doc-1' => ['last_updated' => now()]])` (one payload per id, shallow merge; `merge: false` replaces). No re-embedding. Returns `UpdateMetadataResult` with `updated` and `unknown` ids. Prefer it to `upsert()` when only metadata changed.
- Do not call `embed()` per document in a loop; upsert already batches.
- Store-level form for seeders: `Vector::store('pgvector')->upsert('articles', $documents)`.
- Large corpus in the background: `->queue()->upsert($documents)` returns an `IngestRun` (one job per batch, `Vector::ingestRun($id)`, `VectorIngestProgressed` / `VectorIngestCompleted` / `VectorIngestFailed`, `php artisan vector:ingest:resume {run}`). It refuses `onProgress()` and precomputed vectors; `queue('sync')` runs inline, also on the memory store. Do not run two queued ingests of the same ids at once: [references/ingestion.md](references/ingestion.md#queued-ingest).
- More: [references/ingestion.md](references/ingestion.md).

Docs: `/docs/vector-guide-batch-ingestion`, `/docs/vector-guide-queued-ingest`, `/docs/vector-quick-start`

## Chunk long documents

Rule 4 applies: chunk only documents too long to embed whole. With a reranker, whole help-center articles ranked better than 2,000-character chunks at 30, 50 and 100 candidates.

```php
use AgenticGraph\Vector\Ingest\TextSplitter;

$splitter = new TextSplitter(size: 2000, overlap: 200); // characters, about 500 English tokens

Vector::store('pgvector')->collection('articles')
    ->upsertChunks([Document::make('kb-42', $body, ['title' => 'Custom domains'])], $splitter);

$results = Vector::store('pgvector')->collection('articles')
    ->similarTo($question)
    ->rerank(using: 'voyage', candidates: 50) // example driver name; chunks are reranked, then collapsed
    ->collapse('parent_id')
    ->take(5)
    ->get();
```

- With chunks, raise rerank candidates: `candidates` counts chunks, and 30 chunks hold about 24 documents. Start at 50.
- Chunk ids are `{parentId}#{n}`; metadata is the parent's plus `parent_id` and `chunk`.
- Always `collapse('parent_id')` a chunk search: one hit per document, its best chunk as `content`. It fetches `vector.collapse.overfetch` (3) x take() chunks, so fewer hits come back when they hold fewer documents.
- Re-ingest with `upsertChunks()`: it upserts the new chunks, then deletes that parent's chunks with `chunk` >= the new count, so the document stays findable. It refuses `queue()`. For a first queued load, `queue()->upsert(array_merge(...array_map($splitter->chunk(...), $documents)))`.
- Cite `metadata['parent_id']` and the parent's title or source field (rule 5).

Docs: `/docs/vector-guide-chunking`

## Search

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
- `SearchResult`: `hits` (`SearchHit`: `id`, `score`, `content`, `metadata`, `scores`), `count()`, `first()`, `pluck()`, `toContext()`, `filterByScore()`, `averageScore()`, `merge()`, `unique()`, `toArray()`.
- `toContext()` concatenates hit content (default separator `"\n---\n"`). It is not an answer.
- `fetch($ids)` loads by id. Its scores are always `1.0`, not similarity.
- `minScore()` filters in SQL. `filterByScore()` filters in PHP after `get()`; use it for experiments.

Docs: `/docs/vector-reference-facades-and-builder`, `/docs/vector-reference-value-objects`, `/docs/vector-quick-start`

### Hybrid search

```php
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;

Vector::store('pgvector')->createCollection(new CollectionDefinition(name: 'help_center', keyword: true));

$results = Vector::store('pgvector')
    ->collection('help_center')
    ->similarTo('Where is the Print Certification button?')
    ->hybrid()                    // depth: 50, k: 60, match: 'any', keywordWeight: 0.25
    ->minScore(0.3)
    ->take(5)
    ->get();

$results->first()?->scores;       // ['vector' => float, 'keyword' => ?float, 'fused' => float]
```

- Needs the `Keyword` capability (pgvector, memory) and a collection with `keyword: true`. An existing collection gets it with `php artisan vector:migrate {name} --keyword` (a copy, no embedding calls). Hybrid on a collection without it throws `VectorQueryException`.
- Vector and Postgres full text candidates (up to `max(depth, take)` each, same filter) are fused by weighted reciprocal rank fusion: `1 / (k + dense rank) + keywordWeight / (k + keyword rank)`. `keywordWeight` defaults to `vector.hybrid.keyword_weight` (0.25); 0 gives exactly the dense results.
- Measured: hybrid did not beat dense on top-10 quality, and reranked hybrid matched reranked dense within noise on help-center benchmarks. Recommend dense plus a reranker. Never switch an app to hybrid without its own golden set showing a gain.
- Hits come in fused order, not by `score`. Never threshold `scores['fused']` (at most about 0.033) or `scores['keyword']` (rule 3).
- Match mode: `hybrid(match: 'any')` (default, `vector.hybrid.match`) matches documents with any of the query's words, ranked by `ts_rank_cd`; use it for questions. `hybrid(match: 'websearch')` needs every word and reads `"phrases"`, `-excluded`, `or`; use it for search-box input. Questions in `websearch` mode rarely match anything.
- `keywords('...')` sets the keyword query; it defaults to the `similarTo()` text and is required with `usingVector()`.
- Languages: set `lang` metadata on documents. `en`/`english`, `es`/`spanish` (by default) pick the text search configuration; other values and none use `simple`, which keeps stop words ("how", "the"), so `any` mode matches on them. `languages:` and `languageField:` change the map; changing it is a migration. Accent folding is opt-in through the language map.
- The memory store matches with `simple` semantics for every document, in both modes.

Docs: `/docs/vector-guide-hybrid-search`

### Reranking

```php
use AgenticGraph\Vector\Facades\Vector;

$results = Vector::store('pgvector')
    ->collection('help_center')
    ->similarTo('How do I print my certificate?')
    ->minScore(0.3)                           // on the vector similarity, before reranking
    ->rerank(using: 'cohere', candidates: 30) // a vector.rerank.providers name (example)
    ->take(5)
    ->get();

$results->first()?->scores;                   // ['vector' => float, 'rerank' => ?float]
```

- The store returns `max(candidates, take)` hits (vector or hybrid), the reranker orders them, the top `take` come back. Works on every store; no capability.
- Measured on three benchmarks (top 30 reranked to 10): nDCG@10 +0.06 to +0.20 over dense. The two hosted drivers were close on quality, and the cheaper one cost a fifth to a third of the price per query. An LLM reranker through `prism` added about 2.9 s per search; the hosted drivers about 1.3 s. Measure your own set before you pick one.
- Drivers in `vector.rerank.providers`: `cohere` (`VECTOR_RERANK_COHERE_MODEL`, `COHERE_API_KEY`), `voyage` (`VECTOR_RERANK_VOYAGE_MODEL`, `VOYAGE_API_KEY`), `prism` (an LLM: `VECTOR_RERANK_PRISM_PROVIDER`, `VECTOR_RERANK_PRISM_MODEL`), `null` (store order) and `deterministic` (query word overlap) for tests. No default reranker or model; config entry names are examples.
- Rule 3: `score` stays the vector similarity, and `minScore()` applies before reranking. Order by `scores['rerank']`; its scale depends on the reranker. Never threshold it.
- The rerank query is the `similarTo()` text; with `usingVector()` pass `rerank(query: ...)`. `hybrid(depth: ...)` is per-list depth before fusion, `rerank(candidates: ...)` is what the reranker reads.
- `fail_open` (default): an error or timeout returns the store's first `take` hits without `scores['rerank']` and fires `VectorRerankFailed`; the search does not throw.
- Several searches per question: merge hits by id, then `Vector::reranker('cohere')->rerank($query, $hits, 5)` once (`RerankResult`: `hits`, `model`, `units`, `tokens`, `failedOpen`, `inputTokens`, `outputTokens`). Never merge separately reranked lists by rerank score.
- Own driver: implement `RerankerContract`, register with `Vector::rerankers()->extend('driver', fn ($app, array $config, string $name) => ...)`, test with `RerankerConformanceTests`.

Docs: `/docs/vector-guide-reranking`

### Scores

| Metric | Score | Range |
|---|---|---|
| `cosine` (default) | `1 - distance` | [-1, 1] |
| `l2` | `1 / (1 + distance)` | (0, 1] |
| `inner_product` | `-distance` | unbounded |

- Cosine scores are not percentages. Their scale depends on the embedding model. Measure the strong-match and noise bands on your corpus, then set `minScore()` between them. Without a floor, unrelated queries still return the noise band.
- Re-tune when you change the metric or the embedding model. Thresholds do not carry across metrics or models.
- Ignore scores from `fetch()` and from the `deterministic` provider.

Docs: `/docs/vector-guide-score-ergonomics`

## Build RAG

The package retrieves. Prompting, generation and citations are your code.

- In a controller: search, `toContext()` into the system prompt, the question as the user message, then Prism `->asText()`. `generate()` is deprecated.
- In a graph: a retrieve node (`AbstractNode`) writes `context`, `retrieved_ids` and `retrieved_scores` to state; a generate node (`PrismNode`, `$this->llm()->prompt(...)->vars(...)->text()`) answers; a cite node formats from state. Register the graph class under `config('agenticgraph.graphs')` and run it by name.
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
- pgvector and memory support `ListIds`, `UpdateMetadata`, `DeleteByFilter`, `FilterNot` (`ne`, `not_in`), `FilterContains`, `FilterRange`, `Keyword` (`hybrid()`) and `Aliases`. An unsupported capability is not a roadmap.
- The builder checks the capability before each operation and throws `VectorUnsupportedOperationException`.

Docs: `/docs/vector-guide-capability-discovery`

## Delete and list

- `delete('doc-1')`, `delete(['doc-1', 'doc-2'])`, builder filters then `delete()`, or `delete(filter: ['lang' => 'fr'])`.
- `deleteByFilter(NormalizedFilter::fromAssociative([...]))` takes a filter object.
- A delete with no ids and no filters, or with ids and a filter together, throws `VectorQueryException`.
- Listing ids is paged. The cursor is opaque; order is the store's; every id appears once.

```php
$cursor = null;
do {
    $page = Vector::store('pgvector')->collection('articles')->where('lang', 'en')->listIds(cursor: $cursor, limit: 500);
    // $page->ids
    $cursor = $page->nextCursor;
} while ($cursor !== null);
```

- `IdPage`: `ids` (strings) and `nextCursor` (null on the last page).

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

Docs: `/docs/vector-reference-facades-and-builder`, `/docs/vector-configuration`

## Test

- Set `VECTOR_STORE=memory` and `VECTOR_EMBEDDINGS_PROVIDER=deterministic` in `phpunit.xml` (8 dimensions, no API calls).
- `queue('sync')` runs queued ingest inline, on the memory store too.
- Tests never assert on deterministic scores (rule 7).

## Events and spans

Two separate channels.

- **Events** (`AgenticGraph\Vector\Events`): `VectorOperationCompleted` (`operation`, `store`, `collection`, `durationMs`, `resultCount`, `documentCount`, `embeddingMs`, `searchMode`, `rerankMs`) and `VectorOperationFailed` (adds `exception`). One per store operation, collection management and store-level calls included, when `events.enabled` is on: controller, job or node, with or without AgenticGraph. `store` is the store name.
- `VectorMigrationProgressed` (`store`, `alias`, `source`, `target`, `phase`, `copied`, `total`): per migration batch and phase change. A migration is one `vector.migrate` span (`vector.result_count` is the documents copied); `vector:migrate` with tracing on starts its own trace (root span `vector:migrate`). A queued ingest dispatched inside a traced node carries that trace: each batch's `vector.upsert` span hangs off the trace root with a link to the node span that queued it, in a `continued` segment (a resumed batch keeps it).
- `VectorEmbeddingCompleted` (`model`, `inputCount`, `tokens`, `durationMs`): one per `embed()` / `embedBatch()` of the built-in providers; `tokens` is the provider's reported usage (null for deterministic). Use it for embedding cost.
- `VectorRerankCompleted` (`reranker`, `model`, `documentCount`, `units`, `tokens`, `durationMs`, `inputTokens`, `outputTokens`) per rerank request, `VectorRerankFailed` (adds `exception`, `failedOpen`) on an error or timeout. Use units and tokens for rerank cost; the package never computes money.
- **Spans**: a `vector` span named `vector.{operation}` exists only when the call runs inside an AgenticGraph node and AgenticGraph tracing is on. It nests under the node.
- Span attributes (schema v4) are counts and timings, never query text or document bodies: `vector.store`, `vector.collection`, `vector.operation`, `vector.top_k`, `vector.result_count`, `vector.latency_ms`, `vector.embedding_ms`, `vector.score_min`, `vector.score_max`, `vector.filter_count`, `vector.search_mode`, and on reranked searches `vector.reranker`, `vector.rerank_ms`, `vector.rerank_candidates`, `vector.rerank_status` (`ok` or `failed_open`).
- A `similarTo()` search embeds inside its span: `vector.embedding_ms` (event `embeddingMs`) is that part of the latency, and an embedding failure fires `VectorOperationFailed` for the search. Upsert and insert put the document embedding time there too.
- Without AgenticGraph installed, search runs and events fire with no span overhead.
- Turning tracing on, drivers, and seeing the span in tests: use the `agenticgraph-tracing` skill.

Docs: `/docs/vector-guide-tracing-integration`

## Exceptions

All extend `VectorException` (`AgenticGraph\Vector\Exceptions`).

| Exception | Usual cause |
|---|---|
| `VectorQueryException` | No `similarTo`/`usingVector`, delete without ids or filters or with both, collection redefined, invalid index options, alias or migration refused |
| `VectorDuplicateDocumentException` | `insert()` with an existing or repeated id (a `VectorQueryException`) |
| `VectorDimensionMismatchException` | Vector width differs from the collection's dimensions |
| `VectorExtensionMissingException` | pgvector not installed and cannot be created |
| `VectorEmbeddingException` | Embedding provider call failed (key, Prism config). `retryable` (429, 5xx, timeout) and `status` (HTTP status or null) |
| `VectorInvalidMetadataException` | Non-scalar metadata |
| `VectorInvalidFilterException` | Nested key, non-scalar or mixed-type value, unknown operator, range on a non-ISO string |
| `VectorUnsupportedOperationException` | Store lacks the feature; check `capabilities()` |
| `VectorCollectionNotFoundException` | Collection not created |
| `VectorConnectionException` | Unexpected database error |
| `VectorNotImplementedException` | Driver method not implemented |

Docs: `/docs/vector-reference-exceptions`

## Limitations

- No in-place collection change. Dimensions, metric, index and model change through `Vector::migrate()` / `vector:migrate`.
- No tenant isolation yet. Named stores exist; there is no routing between them.
- No metadata index. pgvector searches with a filter use HNSW iterative scan (`iterative_scan`, default `relaxed_order`, pgvector 0.8+) up to `max_scan_tuples` rows; a filter matching very few documents can still return fewer than `take()` hits.
- Scores are not probabilities.
- Runtime config needs the manager reset (see Config).
- Octane is not verified for the vector managers (see the limitations page).

Docs: `/docs/vector-limitations`
