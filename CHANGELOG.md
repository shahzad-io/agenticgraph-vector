# Changelog

All notable changes to `shahzad/agenticgraph-vector` will be documented in this file.

## Unreleased

### Changed
- These config keys read env values. The defaults are the previous fixed values.

| Key | Env | Default |
|---|---|---|
| `embeddings.default` | `VECTOR_EMBEDDINGS_PROVIDER` | `prism` |
| `embeddings.providers.prism.provider` | `VECTOR_EMBEDDINGS_PRISM_PROVIDER` | `openai` |
| `embeddings.providers.prism.model` | `VECTOR_EMBEDDINGS_MODEL` | `text-embedding-3-small` |
| `embeddings.providers.prism.dimensions` | `VECTOR_EMBEDDINGS_DIMENSIONS` (int) | `1536` |
| `embeddings.providers.prism.api_key` | `VECTOR_EMBEDDINGS_API_KEY` (empty is `null`) | `null` |
| `stores.pgvector.batch_size` | `VECTOR_PGVECTOR_BATCH_SIZE` | `100` |
| `stores.pgvector.install_extension` | `VECTOR_PGVECTOR_INSTALL_EXTENSION` | `true` |
| `events.enabled` | `VECTOR_EVENTS_ENABLED` | `true` |

Apps with a published `config/vector.php` keep their old copy. Republish it or add the env lookups to use these keys.
