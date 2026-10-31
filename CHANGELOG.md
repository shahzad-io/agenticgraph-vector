# Changelog

All notable changes to `shahzad/agenticgraph-vector` will be documented in this file.

## 0.1.0 (unreleased)

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

**Compatible core:** `shahzad/agenticgraph` 0.2.x, optional (tracing and `context()`). The package works without it.

### Fixed

- `vector:migrate --dry-run` no longer changes the database schema. Reads (`listCollections()`, `listAliases()`, `describeCollection()`, `countDocuments()`, `collectionExists()`, the migration record) no longer create the pgvector extension or the registry, alias and migration tables: a missing registry means nothing is registered yet. A dry run works for a database user without CREATE rights.
