# Changelog

All notable changes to `agenticgraph/vector` will be documented in this file.

## 0.2.0 - Unreleased

Several stores behind one contract, and a Qdrant driver.

### Added

- **Qdrant driver** (`qdrant`, Qdrant v1.20+ over REST through the Laravel HTTP client, no SDK, no automatic retry). Config `stores.qdrant`: `url`, `api_key`, `timeout`, `connect_timeout`, `batch_size`, `collection_prefix`. Capabilities: `ListIds`, `UpdateMetadata`, `DeleteByFilter`, `FilterNot`, `FilterContains`, `FilterRange`, `Aliases`. Limits:
  - no keyword or hybrid search (`keyword: true` is refused at create);
  - no migrations: create a new collection, copy the documents, and switch an alias with `pointAlias()`;
  - filters on declared fields only, and a new filterable field needs a new collection;
  - `insert()` checks the ids, then writes in `insert_only` mode: in a race with another writer the existing point is kept and that duplicate is not reported;
  - `updateMetadata()` is one batch request, not atomic;
  - two Qdrant stores with the same URL and the same `collection_prefix` share their collections.
- **Neutral `Metric` enum** (`cosine`, `l2`, `inner_product`) used by every contract class and store.
- **Declared filterable fields:** `CollectionDefinition::$filterable` maps a field to a `FieldType` (`Keyword`, `Integer`, `Float`, `Bool`, `Datetime`, `KeywordList`). A declared field is validated on write, and its operators give the same result on every store.
- **New capability flags:** `KeywordWebsearch`, `KeywordLanguages`, `AtomicBatches`, `Migrations` (split from `Aliases`, with its own `MigrationStoreContract`), `UndeclaredFilters`.
- **`table_prefix`** on a pgvector store, so two pgvector stores can share one connection.

### Changed

- A zero vector under `cosine` is refused on insert, upsert and search with `VectorQueryException`. `l2` and `inner_product` accept it.
- Floats keep their type: metadata `3.0` comes back as `3.0`, and an integer written to a declared `Float` field is stored as a float. Data written before keeps its old form.
- `pointAlias()` creates the alias when it does not exist and re-points it when it does. It refuses the name of a physical collection.
- The shipped config no longer sets `stores.pgvector.registry_table`. Its default is `{table_prefix}vector_collection_registry`, the same name as before when `table_prefix` is empty.
- A search after the first one in a request makes one store call: collection descriptions are cached per scoped manager.
- **pgvector search latency.** A dense search binds the query vector once (nine significant digits), caches its registry lookup per store instance, and asks for the index (`SET LOCAL enable_seqscan = off`) when it has no filter. New store setting `prefer_index` (`VECTOR_PGVECTOR_PREFER_INDEX`, default `true`) and search option `exact` (index scans off, index kept). Small collections, whose vectors the planner costs too low, now get index results, which are approximate, where before they got exact ones; pass `exact: true` for the old results. One SciFact search (5,183 documents) goes from about 20 ms to about 2 ms. The dense list of an unfiltered hybrid search asks for the index too (one SciFact hybrid search: about 22 ms to about 13 ms).
- **pgvector filtered search.** A declared field gets a plain filter predicate and its own index, made by `createCollection` and a migration target (a btree, GIN for `keyword_list`); undeclared fields keep the type-guarded form. A filtered dense search on declared fields counts its matches up to T + 1 (T: the larger of `filter_exact_max_rows`, default 2,500, and `filter_exact_min_share`, default 0.01, x the table's row count). At most T it scores them exactly through the field index; above, it walks the vector index (iterative scan, `ef_search` 160 unless set). New store setting `filter_plan` (`auto`, `exact`, `index`, `planner`). A filter on an undeclared field and a filtered hybrid search are left to the planner. On a large table, a filter that keeps about 1% of the rows has no fast path in pgvector: exact scoring grows with the matches (about 37 ms for 10,000 rows of 1,536 dimensions) and the vector index drops about 99 of every 100 rows it visits.

### Deprecated

- `PgVectorMetric`. Use `Metric`. It stays as a thin enum until 1.0.

### Upgrade from 0.1.0

An app on pgvector or memory runs with no code change. Check these points:

- **Custom capability list.** A `MemoryStore` built with your own `StoreCapabilities`, or a custom store from `Vector::extend()`, must list the new flags it supports, or the matching features are refused: `KeywordWebsearch` for the websearch keyword syntax, `KeywordLanguages` for a per-document language map, `AtomicBatches`, `Migrations` (no longer implied by `Aliases`; implement `MigrationStoreContract` too) and `UndeclaredFilters` for filters on fields that `filterable` does not declare.
- **`PgVectorMetric`** is deprecated. Replace it with `Metric`; the cases have the same values.
- **Declared filterable fields.** Optional on pgvector and memory, which keep filtering undeclared fields. Required on Qdrant, which refuses a filter on a field it was not told about. A declared field rejects a value of another type on write.
- **Zero vectors under `cosine`** now throw `VectorQueryException` on insert, upsert and search.
- **Floats keep their type.** Code that compared a returned `3.0` with `3` using `===` sees a float now.
- **`pointAlias()` creates the alias.** Code that called it only to re-point keeps working. A call that used to fail on a missing alias now creates it.
- **`table_prefix` and `registry_table`.** With an empty prefix nothing changes. If your published config still sets `registry_table`, it is used as written. Set a prefix only for a second pgvector store on the same connection; existing collections keep their tables.
- **Qdrant.** Set `VECTOR_QDRANT_URL` and declare every field you filter on. Read the limits above before you move a pgvector collection that uses keyword search or migrations.

## 0.1.0 - 2026-11-06

Initial release. Laravel-native vector retrieval: store documents, embed text, search, and hand the results to your LLM layer.

- **Stores:** PostgreSQL with pgvector (HNSW or IVFFlat) and an in-memory store for tests. Stores are named in config; add your own with `Vector::extend()` and check it with the `StoreConformanceTests` trait.
- **Embeddings through Prism:** any Prism embedding provider, several named providers in one app, an optional `url` for an OpenAI-compatible gateway, and timeouts and retries with sensible defaults. Each collection remembers the provider it was created with.
- **Fluent search:** `similarTo()` or `usingVector()`, `take()`, `minScore()`, typed metadata filters (`where()` with comparison operators, `whereIn()`, `whereNotIn()`, `whereContains()`, date ranges) and `collapse()` for one hit per parent document.
- **Hybrid search:** Postgres full-text search fused with vector search, a per-document language map, and optional accent folding (`vector:text-search:install`). Filtered searches use pgvector's iterative scan, so a selective filter still returns `take()` hits.
- **Reranking:** Cohere, Voyage or any LLM through Prism, with a total time budget and fail-open by default (a failed rerank returns the vector order).
- **Ingest:** `upsert()`, `insert()`, `TextSplitter` with `upsertChunks()` for long documents, metadata-only updates, and `queue()` for background ingest with a tracked run and `vector:ingest:resume`.
- **Schema changes without downtime:** aliases and `vector:migrate` (copy, or re-embed with a new model), with rollback, plus `vector:index:rebuild` that does not block searches or writes.
- **Observability:** events for every operation, embedding and rerank, and `vector` spans in AgenticGraph traces when core is installed. `SearchResult::toContextItems()` feeds core's `$this->llm()->context()`.
- Safe under Laravel Octane and queue workers.

**Requirements:** PHP 8.3+, Laravel 12.61.1+ or 13.12+, `prism-php/prism` `^0.102.0`, PostgreSQL with pgvector (0.8+ for iterative scan).

**Compatible core:** `agenticgraph/agenticgraph` 0.2.x, optional (tracing and `context()`). The package works without it.

### Fixed

- `vector:migrate --dry-run` no longer changes the database schema. Reads (`listCollections()`, `listAliases()`, `describeCollection()`, `countDocuments()`, `collectionExists()`, the migration record) no longer create the pgvector extension or the registry, alias and migration tables: a missing registry means nothing is registered yet. A dry run works for a database user without CREATE rights.
