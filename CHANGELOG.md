# Changelog

All notable changes to `shahzad/agenticgraph-vector` will be documented in this file.

## Unreleased

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
