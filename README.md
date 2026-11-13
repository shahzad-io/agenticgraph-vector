# agenticgraph-vector

[![Latest Version on Packagist](https://img.shields.io/packagist/v/agenticgraph/vector.svg?style=flat-square)](https://packagist.org/packages/agenticgraph/vector)
[![Total Downloads](https://img.shields.io/packagist/dt/agenticgraph/vector.svg?style=flat-square)](https://packagist.org/packages/agenticgraph/vector)
[![License](https://img.shields.io/packagist/l/agenticgraph/vector.svg?style=flat-square)](https://packagist.org/packages/agenticgraph/vector)

**Pre-1.0:** minor versions may contain breaking changes. Read [CHANGELOG.md](CHANGELOG.md) before you upgrade.

Laravel-native **vector retrieval**: store documents, embed text, run similarity search, and work with structured results. Standalone-first with optional soft integration into [AgenticGraph](https://github.com/shahzad-io/agenticgraph) tracing when retrieval runs inside graph nodes. This is **not** a RAG framework; RAG is a pattern you build on the primitives.

**Repository:** [github.com/shahzad-io/agenticgraph-vector](https://github.com/shahzad-io/agenticgraph-vector)

**Full documentation:** [agenticgraph.dev/docs/vector-overview](https://agenticgraph.dev/docs/vector-overview)

## Requirements

- PHP 8.3+
- Laravel 12.61.1+ or 13.12+
- A vector store. Drivers: `pgvector` (PostgreSQL with the [pgvector](https://github.com/pgvector/pgvector) extension, typically a **separate** connection from your primary database), `qdrant` (Qdrant v1.20+, see [Qdrant](#qdrant)) and `memory` (in-process, for tests). Every store follows one contract, checked by the shipped `StoreConformanceTests`; a feature a store lacks is a capability it does not declare.

## Compatibility

The three packages are released together:

| Package | Version | Works with |
|---|---|---|
| `agenticgraph/agenticgraph` (core) | 0.2.x | PHP 8.3+, Laravel 12.61.1+ or 13.12+, Prism 0.102 |
| `agenticgraph/vector` | 0.1.x | PHP 8.3+, Laravel 12.61.1+ or 13.12+, Prism 0.102, PostgreSQL with pgvector (0.8+ for iterative scan). Core 0.2.x is optional (tracing) |
| `@agenticgraph/stream-client`, `@agenticgraph/stream-react`, `@agenticgraph/stream-vue` | 0.1.x | Core 0.2.x on the server (stream wire contract v1.1; core 0.2.1 and client 0.1.1 together for custom events and error details), Node.js 18+, React 18+ or Vue 3.4+ for the adapters |

## Installation

```bash
composer require agenticgraph/vector
```

With the pgvector driver, define a pgvector connection in `config/database.php`, then set:

```env
VECTOR_DB_CONNECTION=pgvector
VECTOR_EMBEDDINGS_PRISM_PROVIDER=openai
VECTOR_EMBEDDINGS_MODEL=text-embedding-3-small
OPENAI_API_KEY=your-key
```

There is no default embedding provider or model: set both (the values above are an example). The example connection in the installation guide reads these keys: `VECTOR_PG_HOST` (default `127.0.0.1`), `VECTOR_PG_PORT` (`5432`), `VECTOR_PG_DATABASE` (`myapp_vectors`), `VECTOR_PG_USERNAME` (`postgres`) and `VECTOR_PG_PASSWORD`. They belong to your own `database.php` connection; the package reads only `VECTOR_DB_CONNECTION`.

For queued ingest (`->queue()->upsert()`), publish and run the migration for the run table:

```bash
php artisan vendor:publish --tag=vector-migrations
php artisan migrate
```

See the [installation guide](https://agenticgraph.dev/docs/vector-installation) for details.

### AI assistants

Run `php artisan boost:update`. It finds the AgenticGraph packages and asks which skills to add; tick `agenticgraph-vector`, so your coding assistant knows how to ingest and search with this package. A first-time Boost setup is `php artisan boost:install`.

## Quick example

```php
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;

$dimensions = \AgenticGraph\Vector\Facades\Embeddings::using('prism')->dimensions();

Vector::store('pgvector')->createCollection(
    new CollectionDefinition('articles', dimensions: $dimensions),
);

Vector::store('pgvector')
    ->collection('articles')
    ->upsert([
        Document::make('doc-1', 'Laravel queue workers process Redis-backed background jobs.', [
            'category' => 'laravel',
        ]),
    ]);

$results = Vector::store('pgvector')
    ->collection('articles')
    ->similarTo('How do Laravel queue workers use Redis?')
    ->take(5)
    ->get();

$results->first()?->id;        // 'doc-1'
$results->toContext();         // concatenated content for your LLM layer
```

Scores follow the collection's metric in every store: `cosine` gives the cosine similarity (-1 to 1), `l2` gives `1 / (1 + distance)` (above 0, up to 1), `inner_product` gives the dot product. Declare the metadata fields you filter on with `filterable` (`FieldType`), and every store filters them alike.

## pgvector

A declared field (`filterable`) gets its own index and a plain filter predicate. A filtered search on declared fields counts its matches up to a threshold (store settings `filter_exact_max_rows`, default 2,500, and `filter_exact_min_share`, default 0.01 of the table): below it the search is exact through the field index, above it the search walks the vector index. `filter_plan` (`auto`, `exact`, `index`, `planner`) fixes the choice for a caller that knows its data. A filter on an undeclared field and a filtered hybrid search are left to the Postgres planner.

The registry table is created on first use, and a registry from an older release gets its new columns at runtime (`ALTER TABLE ... ADD COLUMN`): the database user needs the right to create tables and to alter the registry table.

After `vector:migrate`, the old collection stays with its data (and `--rollback` works) until `php artisan vector:migrate {name} --drop-old`. To declare a filterable field on an existing collection: `php artisan vector:migrate {name} --filterable=tenant:keyword` (repeat the option; it replaces the declared fields, so name every field to keep).

Keyword and hybrid search need the `Keyword` capability, which Qdrant does not have. An app that may run on either store asks before it sets `keyword`:

```php
$store = Vector::store();
$keyword = $store->capabilities()->supports(\AgenticGraph\Vector\Enums\StoreCapability::Keyword);
$store->createCollection(new CollectionDefinition('articles', keyword: $keyword));
```

On a large table, a filter that keeps about 1% of the rows has no fast path in pgvector: exact scoring grows with the matches (about 37 ms for 10,000 rows of 1,536 dimensions) and the vector index drops about 99 of every 100 rows it visits.

## Qdrant

```env
VECTOR_STORE=qdrant
VECTOR_QDRANT_URL=http://127.0.0.1:6333
VECTOR_QDRANT_API_KEY=
```

Other keys of `stores.qdrant`: `VECTOR_QDRANT_TIMEOUT` (10 s), `VECTOR_QDRANT_CONNECT_TIMEOUT` (3 s), `VECTOR_QDRANT_BATCH_SIZE` (100), `VECTOR_QDRANT_COLLECTION_PREFIX` (empty). The driver uses the REST API through the Laravel HTTP client, with no SDK and no automatic retry.

Capabilities: `ListIds`, `UpdateMetadata`, `DeleteByFilter`, `FilterNot`, `FilterContains`, `FilterRange`, `Aliases`. Declare every field you filter on:

```php
new CollectionDefinition('articles', dimensions: 1536, filterable: ['lang' => 'keyword', 'price' => 'float']);
```

Limits:

- No keyword or hybrid search: a collection with `keyword: true` is refused at create.
- No migrations: change a collection by creating a new one, copying the documents, and switching an alias with `pointAlias()`.
- Filters on declared fields only (`CollectionDefinition::$filterable`); another field throws `VectorUnsupportedOperationException`.
- A new filterable field needs a new collection.
- `insert()` checks the ids, then writes in Qdrant's `insert_only` mode. If another writer adds the same id between the check and the write, the existing point is kept and the duplicate is not reported.
- `updateMetadata()` is one batch request, not atomic.
- `collection_prefix` (`VECTOR_QDRANT_COLLECTION_PREFIX`, empty by default) is prepended to every collection and alias name the store creates or reads; `listCollections()` and `listAliases()` return only the names with the prefix, without it. Two Qdrant stores with the same URL and the same prefix share their collections.

## Optional AgenticGraph integration

```bash
composer require agenticgraph/agenticgraph
```

Vector operations inside graph nodes nest as `vector` spans in AgenticGraph traces. The package works fully without AgenticGraph.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.

Started from spatie/package-skeleton-laravel.
