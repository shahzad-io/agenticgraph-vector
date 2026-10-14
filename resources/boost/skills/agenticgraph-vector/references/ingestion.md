# Ingestion and configuration examples

Docs: `/docs/vector-installation`, `/docs/vector-configuration`, `/docs/vector-guide-batch-ingestion`, `/docs/vector-limitations`

## A dedicated connection

```php
// config/database.php
'connections' => [
    'pgvector' => [
        'driver' => 'pgsql',
        'host' => env('VECTOR_PG_HOST', '127.0.0.1'),
        'port' => env('VECTOR_PG_PORT', '5432'),
        'database' => env('VECTOR_PG_DATABASE', 'myapp_vectors'),
        'username' => env('VECTOR_PG_USERNAME', 'postgres'),
        'password' => env('VECTOR_PG_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
        'sslmode' => 'prefer',
    ],
],
```

The `VECTOR_PG_HOST` family of names is your app's own env, not package config. The package reads only `VECTOR_DB_CONNECTION`, the connection name.

## Batch size

Resolution order: `vector.stores.pgvector.batch_size` (published as 100), then `vector.batch.size` (100).

```php
Vector::store('pgvector')->batchSize();   // effective chunk size
```

With 120 documents and a batch size of 50, `onProgress` fires at 50, 100 and 120, with three `embedBatch()` calls.

## Precomputed vectors

```php
use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\ValueObjects\Document;

$texts = ['First document text', 'Second document text'];
$vectors = Embeddings::using('prism')->embedBatch($texts);

Vector::store('pgvector')
    ->collection('knowledge_base')
    ->upsert([
        Document::make('doc-1', $texts[0], vector: $vectors[0]),
        Document::make('doc-2', $texts[1], metadata: ['lang' => 'en'], vector: $vectors[1]),
    ]);
```

A vector whose width differs from the collection throws `VectorDimensionMismatchException`.

## A precomputed query vector

```php
$vector = Embeddings::using('prism')->embed('PostgreSQL pgvector cosine similarity');

$results = Vector::store('pgvector')->collection('articles')->usingVector($vector)->take(3)->get();
```

## Runtime config

The managers keep the first resolved store and provider. After changing config at runtime (tests, multi-tenant), reset them:

```php
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Support\Facades\DB;

config(['vector.stores.pgvector.connection' => 'pgvector']);

DB::purge('pgvector');
app()->forgetInstance(EmbeddingsManager::class);
app()->forgetInstance(VectorManager::class);
Vector::clearResolvedInstance(VectorManager::class);
```

## Tests

```php
// Pest setup or TestCase::getEnvironmentSetUp
config(['vector.embeddings.default' => 'deterministic']);   // 8 dims, no API calls
```

Create test collections without `dimensions` so they match the provider. Do not assert real score thresholds against `deterministic`.
