# agenticgraph-vector

[![Latest Version on Packagist](https://img.shields.io/packagist/v/shahzad/agenticgraph-vector.svg?style=flat-square)](https://packagist.org/packages/shahzad/agenticgraph-vector)
[![Total Downloads](https://img.shields.io/packagist/dt/shahzad/agenticgraph-vector.svg?style=flat-square)](https://packagist.org/packages/shahzad/agenticgraph-vector)
[![License](https://img.shields.io/packagist/l/shahzad/agenticgraph-vector.svg?style=flat-square)](https://packagist.org/packages/shahzad/agenticgraph-vector)

Laravel-native **vector retrieval** — store documents, embed text, run similarity search, and work with structured results. Standalone-first with optional soft integration into [AgenticGraph](https://github.com/shahzad-io/agenticgraph) tracing when retrieval runs inside graph nodes. This is **not** a RAG framework; RAG is a pattern you build on the primitives.

**Repository:** [github.com/shahzad-io/agenticgraph-vector](https://github.com/shahzad-io/agenticgraph-vector)

**Full documentation:** [agenticgraph.dev/docs/vector-overview](https://agenticgraph.dev/docs/vector-overview)

## Requirements

- PHP 8.2+
- Laravel 11.x, 12.x, or 13.x
- PostgreSQL with the [pgvector](https://github.com/pgvector/pgvector) extension (typically a **separate** connection from your primary database)

## Installation

```bash
composer require shahzad/agenticgraph-vector
```

Define a pgvector connection in `config/database.php`, then set:

```env
VECTOR_DB_CONNECTION=pgvector
OPENAI_API_KEY=your-key
```

See the [installation guide](https://agenticgraph.dev/docs/vector-installation) for details.

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
composer require shahzad/agenticgraph
```

Vector operations inside graph nodes nest as `vector` spans in AgenticGraph traces. The package works fully without AgenticGraph.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.
