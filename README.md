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
- PostgreSQL with the [pgvector](https://github.com/pgvector/pgvector) extension (typically a **separate** connection from your primary database)

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

Define a pgvector connection in `config/database.php`, then set:

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

## Optional AgenticGraph integration

```bash
composer require agenticgraph/agenticgraph
```

Vector operations inside graph nodes nest as `vector` spans in AgenticGraph traces. The package works fully without AgenticGraph.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.

Started from spatie/package-skeleton-laravel.
