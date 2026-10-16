# Changelog

All notable changes to `shahzad/agenticgraph-vector` will be documented in this file.

## Unreleased

### Added (B4: benchmark harness)
- `AgenticGraph\Vector\Events\VectorEmbeddingCompleted` (`model`, `inputCount`, `tokens`, `durationMs`): one per `embed()` and `embedBatch()` of the Prism and deterministic providers, when `vector.events.enabled` is on. `tokens` is the usage Prism reports (null when the provider reports none, and for the deterministic provider). Custom providers can fire it with `VectorEmbeddingCompleted::record()`.
- Upsert and insert embed documents inside `TraceSpan::embedding()`, so the `vector.upsert` / `vector.insert` span carries `vector.embedding_ms` and the event carries `embeddingMs` (both null when every document has a vector). Before, only `similarTo()` searches set them.
- Dev only (export-ignored): `bin/vector-bench` retrieval benchmark runner, `benchmarks/datasets.json` (SciFact and WixQA pinned by revision and sha256), `benchmarks/export-datasets.py`. See `benchmarks/README.md`. `benchmarks/export-scifact.py` is replaced by `export-datasets.py`; the Ollama PHPUnit benchmark (`tests/Benchmark`) reads the pinned export and reports the fixed metrics.

### Fixed (B4)
- Benchmark recall@k used the hit rate (any relevant document in the top k). It is now BEIR recall (relevant found in the top k divided by all relevant); the hit rate is reported as hit@k, and MRR is cut at 10.

### BREAKING (B3: store contract)
- **Capabilities shape.** `StoreCapabilities` is backed by the new `AgenticGraph\Vector\Enums\StoreCapability` enum: `new StoreCapabilities(StoreCapability ...$capabilities)`, `supports(StoreCapability $capability): bool`, `$capabilities` (list). Removed: `supportsDeleteByFilter()`, `supportsNamespaces()`, `supportsHybridSearch()`, `supportsReranking()`, `supportsTenantIsolation()` and `StoreCapabilities::pgvector()`. Use `supports(StoreCapability::DeleteByFilter)`. Cases: `ListIds`, `UpdateMetadata`, `DeleteByFilter`, `FilterNot`, `FilterContains`, `FilterRange`, `Keyword`, `Aliases`.
- **Delete is unambiguous.** `Builder::delete()` with ids and a filter (from `where()` or the `$filter` argument) throws `VectorQueryException`. Before, ids with `where()` ignored the filter, and ids with `$filter` ignored the ids.
- **Duplicate ids.** `insert()` with an id the collection holds, or one repeated in the call, throws `VectorDuplicateDocumentException` (extends `VectorQueryException`). pgvector reported it as `VectorConnectionException`.
- **Span schema v2.** `VectorSpanSchema::VERSION` is `v2`; adds `vector.embedding_ms`. `vector.store` (and the events' `store`) is the name of the store used, not `vector.default`. A `similarTo()` search embeds inside the search span: `vector.latency_ms` and `durationMs` now include the query embedding.
- **Named store config.** `Vector::store($name)` reads `vector.stores.{$name}` and builds it with its `driver` key (before, the name was the driver and pgvector was hard-coded). A name not in `vector.stores` throws `InvalidArgumentException` unless a driver of that name was registered with `Vector::extend()`. `extend()` callbacks receive `($app, array $config, string $name)`.
- **Contracts.** `VectorStoreContract` declares `collection(string $name): Builder`. `DocumentStoreContract` gains `listIds()` and `updateMetadata()`. Custom stores must implement them (throw `VectorUnsupportedOperationException` and leave the capability out if unsupported).
- **One instrumented path.** `Vector::store()` returns the store wrapped in `AgenticGraph\Vector\Stores\InstrumentedStore`. Every operation, store-level calls and collection management included, dispatches one event and (inside a traced node) one span. Code that type-checks `Vector::store()` against `PgVectorStore` must check `VectorStoreContract` instead. Listeners now also see `create_collection`, `collection_exists`, `list_collections`, `delete_collection`, `health_check`, `list_ids` and `update_metadata`.
- **Type-exact equality filters.** `where()` / `whereIn()` only match a field of the value's JSON type. A string no longer matches a number with the same text (`'5'` matched a stored `5`). A number or boolean no longer fails when a document holds another type under that key (pgvector raised a cast error, reported as `VectorConnectionException`); such documents do not match. Invalid filters now throw `VectorInvalidFilterException` when the condition is built, not when the query runs.
- `VectorTraceContext::$searchQuery` is replaced by `?int $topK`.

### Added (B3)
- `where($field, $operator, $value)` with `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=` or `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `contains`; `whereNotIn()`; `whereContains()`. `FilterOperator` gains `NotEquals`, `NotIn`, `Contains`, `GreaterThan`, `GreaterThanOrEqual`, `LessThan`, `LessThanOrEqual`. `ne` / `not_in` match documents without the field; `contains` matches array fields only; ranges are type-guarded.
- Dates: `DateTimeInterface` values in metadata and filters are stored and compared as UTC ISO 8601 strings, `Y-m-d\TH:i:s\Z` (`AgenticGraph\Vector\Support\UtcDate`). Ranges on any other string throw `VectorInvalidFilterException`.
- `Builder::upsert()` with an id more than once keeps the last copy (pgvector failed with a database error). `onProgress` counts the remaining documents.
- `Builder::listIds(?string $cursor = null, int $limit = 100): IdPage`: keyset pagination on id with the builder's filters.
- `Builder::updateMetadata(array $metadataById, bool $merge = true): UpdateMetadataResult`: metadata-only change, no re-embedding; shallow merge or replace.
- `CollectionDefinition` gains `index` (`hnsw` or `ivfflat`) and `indexOptions` (HNSW `m`, `ef_construction`; IVFFlat `lists`), validated by pgvector. The registry gains a nullable `index_options` JSONB column holding the requested index; an existing registry gets it on first use (inside the once-per-process registry check, no migration). Idempotent create compares the requested index with the real one in Postgres. IVFFlat rebuilds after each write with the registered `lists`.
- `memory` store (`AgenticGraph\Vector\Stores\MemoryStore`, config `stores.memory`): reference implementation in PHP, exact search with pgvector's score math. `VECTOR_STORE` env for `vector.default`.
- `AgenticGraph\Vector\Testing\StoreConformanceTests` (trait, abstract `makeStore()`) and `Testing\CountingEmbeddingProvider`. PHPUnit is in `suggest`, not `require`.
- Events gain nullable `embeddingMs`. An embedding failure in `similarTo()` fires `VectorOperationFailed` for the search.
- `stores.pgvector.verify_extension` (`VECTOR_PGVECTOR_VERIFY_EXTENSION`, default `true`). The extension check and the registry table check run once per store instance (per process), not per operation.

### Fixed (B3)
- `Builder::deleteByFilter()` and store-level `deleteByFilter()` with an empty filter throw `VectorQueryException`; pgvector deleted every row.
- pgvector stored empty metadata as a JSON array (`[]`); it is now an object (`{}`).
- pgvector wrapped `VectorInvalidFilterException` and `VectorUnsupportedOperationException` thrown inside an operation as `VectorConnectionException`; every `VectorException` now passes through.

### BREAKING
- Requires `prism-php/prism` `^0.100.1`.
- The Prism embedding provider has no vendor default. It was `openai` with `text-embedding-3-small` and 1536 dimensions. Set `VECTOR_EMBEDDINGS_PRISM_PROVIDER` and `VECTOR_EMBEDDINGS_MODEL` (or `provider:model` in the model key), otherwise the first embedding call throws `VectorEmbeddingException` naming those keys. A model without a provider no longer means `openai`.
- `VECTOR_EMBEDDINGS_DIMENSIONS` defaults to `null` (it was 1536). With `null`, `createCollection()` without dimensions asks the provider: one extra embed call with a short probe text, once per process, skipped if that provider already embedded something. Set the key to avoid the call and send a fixed size.

### Changed
- These config keys read env values. The defaults are the previous fixed values.

| Key | Env | Default |
|---|---|---|
| `embeddings.default` | `VECTOR_EMBEDDINGS_PROVIDER` | `prism` |
| `embeddings.providers.prism.provider` | `VECTOR_EMBEDDINGS_PRISM_PROVIDER` | none (required) |
| `embeddings.providers.prism.model` | `VECTOR_EMBEDDINGS_MODEL` | none (required) |
| `embeddings.providers.prism.dimensions` | `VECTOR_EMBEDDINGS_DIMENSIONS` (int, empty is `null`) | `null` (ask the provider, one embed call) |
| `embeddings.providers.prism.api_key` | `VECTOR_EMBEDDINGS_API_KEY` (empty is `null`) | `null` |
| `stores.pgvector.batch_size` | `VECTOR_PGVECTOR_BATCH_SIZE` | `100` |
| `stores.pgvector.install_extension` | `VECTOR_PGVECTOR_INSTALL_EXTENSION` | `true` |
| `events.enabled` | `VECTOR_EVENTS_ENABLED` | `true` |

Apps with a published `config/vector.php` keep their old copy. Republish it or add the env lookups to use these keys.
