<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Vector Store
    |--------------------------------------------------------------------------
    |
    | The default store used when Vector::store() is called without a name.
    | Set VECTOR_STORE=memory in an app's test environment to run without Postgres.
    |
    */

    'default' => env('VECTOR_STORE', 'pgvector'),

    /*
    |--------------------------------------------------------------------------
    | Vector Stores
    |--------------------------------------------------------------------------
    |
    | Named stores. Each entry names its driver: pgvector, qdrant, memory, or a driver
    | registered with Vector::extend(). Vector::store('name') resolves the entry
    | through its driver; several entries may use the same driver.
    |
    */

    'stores' => [

        'pgvector' => [
            'driver' => 'pgvector',

            /*
            | The database connection (defined in config/database.php) that backs
            | the vector store. This can be a SEPARATE, dedicated pgvector-enabled
            | Postgres connection, distinct from your app's primary database.
            | For example, if your app runs on MySQL, define a separate "pgvector"
            | connection in config/database.php and point this at it. The vector
            | store uses ONLY this connection; your primary database is untouched.
            */
            'connection' => env('VECTOR_DB_CONNECTION', 'pgsql'),

            'index' => 'hnsw',
            'metric' => 'cosine',
            'batch_size' => (int) env('VECTOR_PGVECTOR_BATCH_SIZE', 100),
            // Prefix of this store's tables: the data tables, and the registry, alias and migration tables unless
            // registry_table names the registry. Two stores on one connection with the same prefix and registry
            // share their collections; give each its own prefix to keep them apart. Set it before the first
            // collection: existing tables keep their names.
            'table_prefix' => env('VECTOR_PGVECTOR_TABLE_PREFIX', ''),
            // 'registry_table' => 'vector_collection_registry', // default: {table_prefix}vector_collection_registry
            'install_extension' => (bool) env('VECTOR_PGVECTOR_INSTALL_EXTENSION', true),
            // Check once per process that the vector extension exists. false skips the check.
            'verify_extension' => (bool) env('VECTOR_PGVECTOR_VERIFY_EXTENSION', true),
            'ivfflat_lists' => 100,
            // Searches with a filter keep scanning the HNSW index until they have take() hits (pgvector 0.8+):
            // off, relaxed_order or strict_order. Searches without a filter do not use it.
            'iterative_scan' => env('VECTOR_PGVECTOR_ITERATIVE_SCAN', 'relaxed_order'),
            // An unfiltered, non-exact search on a collection with an index uses the index (SET LOCAL enable_seqscan = off).
            // Without it the planner picks an exact scan on small collections, whose vectors it costs too low.
            'prefer_index' => (bool) env('VECTOR_PGVECTOR_PREFER_INDEX', true),
            // The most rows one iterative scan visits (pgvector's default).
            'max_scan_tuples' => (int) env('VECTOR_PGVECTOR_MAX_SCAN_TUPLES', 20000),
        ],

        // Qdrant v1.20+ over REST. Filters run on declared fields only; no keyword search, no migrations.
        'qdrant' => [
            'driver' => 'qdrant',
            'url' => env('VECTOR_QDRANT_URL'),
            'api_key' => env('VECTOR_QDRANT_API_KEY'),
            'timeout' => (float) env('VECTOR_QDRANT_TIMEOUT', 10),
            'connect_timeout' => (float) env('VECTOR_QDRANT_CONNECT_TIMEOUT', 3),
            'batch_size' => (int) env('VECTOR_QDRANT_BATCH_SIZE', 100),

            // Prepended to every collection and alias name this store creates or reads. Two stores with
            // the same url and the same prefix share their collections.
            'collection_prefix' => env('VECTOR_QDRANT_COLLECTION_PREFIX', ''),
        ],

        // In-process reference store (exact search, no index). Data lives as long as the
        // store instance, so it suits tests, not production.
        'memory' => [
            'driver' => 'memory',
            'metric' => 'cosine',
            'batch_size' => 100,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Default embedding provider and named providers. Each entry names its
    | driver (prism, deterministic, or one registered with Embeddings::extend()),
    | so you can hold several models, for example a second Prism entry while
    | migrating a collection to it. A collection records the provider it was
    | created with and embeds documents and queries with it.
    |
    */

    'embeddings' => [

        'default' => env('VECTOR_EMBEDDINGS_PROVIDER', 'prism'),

        'providers' => [

            'prism' => [
                'driver' => 'prism',
                // No vendor default: set both (example: openai, text-embedding-3-small), or provider:model in VECTOR_EMBEDDINGS_MODEL.
                'provider' => env('VECTOR_EMBEDDINGS_PRISM_PROVIDER'),
                'model' => env('VECTOR_EMBEDDINGS_MODEL'),
                // null when unset or empty: the width comes from the provider. createCollection() without
                // dimensions then costs one extra embed call (a short probe text) per process, unless this
                // provider already embedded something. Set it to skip that call and send a fixed size.
                'dimensions' => env('VECTOR_EMBEDDINGS_DIMENSIONS') ? (int) env('VECTOR_EMBEDDINGS_DIMENSIONS') : null,
                // null when unset or empty: Prism then uses its own provider key (e.g. OPENAI_API_KEY).
                'api_key' => env('VECTOR_EMBEDDINGS_API_KEY') ?: null,
                // null when unset or empty: Prism then uses its own base URL for the provider. Set it for an
                // OpenAI-compatible gateway (for example LiteLLM). Per request, not global.
                'url' => env('VECTOR_EMBEDDINGS_URL') ?: null,
                // Seconds per request. A 429, a 5xx or a connection error or timeout is retried up to `retries`
                // times, waiting retry_sleep_ms x 2^(retry - 1) with jitter (half to all of it), or the
                // provider's Retry-After when longer, up to retry_after_max_ms; a longer Retry-After fails the
                // request as retryable instead. Other errors (400, 401, 413) are not retried. Inside a queued
                // ingest job retries are off: the job's tries and backoff retry the batch.
                'timeout' => (int) env('VECTOR_EMBEDDINGS_TIMEOUT', 30),
                'retries' => (int) env('VECTOR_EMBEDDINGS_RETRIES', 2),
                'retry_sleep_ms' => (int) env('VECTOR_EMBEDDINGS_RETRY_SLEEP_MS', 500),
                'retry_after_max_ms' => (int) env('VECTOR_EMBEDDINGS_RETRY_AFTER_MAX_MS', 2000),
            ],

            'deterministic' => [
                'driver' => 'deterministic',
                'dimensions' => 8,
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Defaults
    |--------------------------------------------------------------------------
    |
    | Global batch chunk size override for ingestion pipelines.
    |
    */

    'batch' => [

        'size' => 100,

    ],

    /*
    |--------------------------------------------------------------------------
    | Queued ingest
    |--------------------------------------------------------------------------
    |
    | ->queue()->upsert($documents) dispatches one job per batch (the store's
    | batch_size) through Bus::batch and records the run in vector_ingest_runs
    | (php artisan vendor:publish --tag=vector-migrations). connection and queue
    | null: the app's default connection and that connection's default queue.
    | On a sync connection the batches run inline.
    |
    | timeout (seconds per batch job) must be below the connection's
    | retry_after, or a slow batch can run twice at once; queue() refuses
    | otherwise. Dimension, metadata and missing-collection errors fail at
    | once, as do embedding errors that are not retryable (400, 401, 413);
    | connection errors and retryable embedding errors (429, 5xx, timeouts)
    | retry after the backoff seconds (the embedding provider's own retries
    | are off inside the job). queue() also refuses when the collection's
    | Prism provider timeout plus 5 s is not below timeout. For large
    | batches raise the provider timeout, this timeout and retry_after.
    |
    */

    'queue' => [

        'connection' => env('VECTOR_QUEUE_CONNECTION') ?: null,

        'queue' => env('VECTOR_QUEUE') ?: null,

        'tries' => (int) env('VECTOR_QUEUE_TRIES', 3),

        'backoff' => [10, 30, 60],

        'timeout' => (int) env('VECTOR_QUEUE_TIMEOUT', 60),

    ],

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | Vector::migrate() and vector:migrate catch up writes made during the
    | copy without a lock until at most this many documents differ, then
    | lock the old collection against writers for one final pass and switch.
    | At most catch_up_max_passes unlocked passes run; if more documents still
    | differ after them, the final pass refuses and a rerun continues.
    |
    */

    'migration' => [

        'catch_up_threshold' => (int) env('VECTOR_MIGRATION_CATCH_UP_THRESHOLD', 500),

        'catch_up_max_passes' => (int) env('VECTOR_MIGRATION_CATCH_UP_MAX_PASSES', 10),

    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid search
    |--------------------------------------------------------------------------
    |
    | The keyword match mode of hybrid() when the call gives none: "any"
    | (documents with any of the query's words, ranked by how many and how
    | close; for natural-language questions) or "websearch" (every word,
    | "quoted phrases", -exclusions, or; for search-box input).
    |
    | keyword_weight multiplies the keyword side's fusion term (dense 1; 0
    | gives the dense results). 0.25 is the benchmark value: it adds
    | relevant candidates at the same budget, but lowers top-10 quality, and
    | reranked it matched reranked dense within noise. Dense plus a
    | reranker is the recommended pipeline.
    |
    */

    'hybrid' => [

        'match' => env('VECTOR_HYBRID_MATCH', 'any'),

        'keyword_weight' => (float) env('VECTOR_HYBRID_KEYWORD_WEIGHT', 0.25),

    ],

    /*
    |--------------------------------------------------------------------------
    | Reranking
    |--------------------------------------------------------------------------
    |
    | Named rerankers for ->rerank(using: 'name') and Vector::reranker('name').
    | Each entry names its driver: cohere, voyage, prism (an LLM through
    | Prism), null (input order), deterministic (query word overlap, for
    | tests), or one registered with Vector::rerankers()->extend(). There is
    | no default: rerank() without a name needs VECTOR_RERANK_PROVIDER.
    | The entry names below are examples; pick your own.
    |
    | fail_open: on a reranker error or timeout, the search returns its own
    | first take() hits (no rerank score) and fires VectorRerankFailed.
    | false lets the exception through.
    |
    | timeout is the total seconds a rerank may take, retries included: a
    | 429, a 5xx or a connection error or timeout is retried up to retries
    | times (waits as for embeddings) while the budget lasts, then fail_open
    | applies. An entry's own timeout, retries or retry_sleep_ms overrides
    | these.
    |
    */

    'rerank' => [

        'default' => env('VECTOR_RERANK_PROVIDER') ?: null,

        'fail_open' => (bool) env('VECTOR_RERANK_FAIL_OPEN', true),

        'timeout' => (int) env('VECTOR_RERANK_TIMEOUT', 5),

        'retries' => (int) env('VECTOR_RERANK_RETRIES', 2),

        'retry_sleep_ms' => (int) env('VECTOR_RERANK_RETRY_SLEEP_MS', 500),

        'providers' => [

            'cohere' => [
                'driver' => 'cohere',
                // No default model: set one, for example rerank-v4.0-pro.
                'model' => env('VECTOR_RERANK_COHERE_MODEL'),
                'api_key' => env('COHERE_API_KEY'),
            ],

            'voyage' => [
                'driver' => 'voyage',
                // No default model: set one, for example rerank-3.
                'model' => env('VECTOR_RERANK_VOYAGE_MODEL'),
                'api_key' => env('VOYAGE_API_KEY'),
            ],

            'llm' => [
                'driver' => 'prism',
                // No vendor default: set both (example: openai, gpt-4.1-mini), or provider:model in VECTOR_RERANK_PRISM_MODEL.
                'provider' => env('VECTOR_RERANK_PRISM_PROVIDER'),
                'model' => env('VECTOR_RERANK_PRISM_MODEL'),
                // null when unset or empty: Prism then uses its own provider key.
                'api_key' => env('VECTOR_RERANK_PRISM_API_KEY') ?: null,
                // null when unset or empty: Prism then uses its own base URL for the provider (a gateway goes here).
                'url' => env('VECTOR_RERANK_PRISM_URL') ?: null,
                // Each passage is cut to this many characters in the prompt.
                'max_characters' => (int) env('VECTOR_RERANK_PRISM_MAX_CHARACTERS', 1500),
            ],

            'null' => [
                'driver' => 'null',
            ],

            'deterministic' => [
                'driver' => 'deterministic',
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Collapse
    |--------------------------------------------------------------------------
    |
    | ->collapse('parent_id') keeps the best chunk per parent document. The
    | search fetches overfetch x take() chunks first (with rerank(), at least
    | that many candidates), so take() parents usually survive the collapse;
    | when they do not, fewer hits come back.
    |
    */

    'collapse' => [

        'overfetch' => (int) env('VECTOR_COLLAPSE_OVERFETCH', 3),

    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | VectorOperationCompleted / VectorOperationFailed events (when enabled).
    |
    */

    'events' => [

        'enabled' => (bool) env('VECTOR_EVENTS_ENABLED', true),

    ],

];
