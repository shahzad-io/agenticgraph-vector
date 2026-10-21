# Ingestion and configuration examples

Docs: `/docs/vector-installation`, `/docs/vector-configuration`, `/docs/vector-guide-batch-ingestion`, `/docs/vector-guide-queued-ingest`, `/docs/vector-limitations`

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

## Queued ingest

Publish the run table (`php artisan vendor:publish --tag=vector-migrations`, then `migrate`); the app also needs `job_batches` and `failed_jobs`.

```php
use AgenticGraph\Vector\Facades\Vector;

$run = Vector::store('pgvector')->collection('knowledge_base')->queue()->upsert($documents);

Vector::ingestRun($run->id)->status; // pending, running, completed, failed
```

- One job per batch of `batchSize()` through `Bus::batch`; each job is one transaction. The job resolves the store by name and the alias per batch, so it follows `vector:migrate`.
- Connection errors and retryable embedding errors (`VectorEmbeddingException::$retryable`: 429, 5xx, timeouts) retry (`vector.queue.tries`, `backoff`; the provider's own retries are off inside the job); other embedding errors (400, 401, 413) and dimension, metadata and missing-collection errors fail the batch at once. `php artisan vector:ingest:resume {run}` retries the failed jobs.
- Refused before dispatch: a store not from `Vector::store()`, `onProgress()`, precomputed vectors, `insert()`, the memory store off `sync`, `vector.queue.timeout` at or above the connection's `retry_after`, and a Prism provider whose `timeout` + 5 s is not below `vector.queue.timeout` (the defaults, 30 s and 60 s, pass).
- No IVFFlat rebuild during or after a run: `php artisan vector:index:rebuild {collection}` after the first bulk load.
- Rows are idempotent by id and counters by batch index. Stale overwrites are not: an older run's retried batch overwrites a newer run's rows. One ingest of a set of ids at a time.

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

The managers keep the first resolved store and provider for the rest of the request or queued job (each Octane request and job gets new ones). After changing config at runtime following a vector call in the same request (tests, multi-tenant), reset them:

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
