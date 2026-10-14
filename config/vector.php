<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Vector Store
    |--------------------------------------------------------------------------
    |
    | The default store used when Vector::store() is called without a name.
    |
    */

    'default' => 'pgvector',

    /*
    |--------------------------------------------------------------------------
    | Vector Stores
    |--------------------------------------------------------------------------
    |
    | Each store adapter is configured here. Index, metric, and batch settings
    | apply to the pgvector adapter.
    |
    */

    'stores' => [

        'pgvector' => [
            'driver' => 'pgvector',

            /*
            | The database connection (defined in config/database.php) that backs
            | the vector store. This can be a SEPARATE, dedicated pgvector-enabled
            | Postgres connection — distinct from your app's primary database.
            | For example, if your app runs on MySQL, define a separate "pgvector"
            | connection in config/database.php and point this at it. The vector
            | store uses ONLY this connection; your primary database is untouched.
            */
            'connection' => env('VECTOR_DB_CONNECTION', 'pgsql'),

            'index' => 'hnsw',
            'metric' => 'cosine',
            'batch_size' => (int) env('VECTOR_PGVECTOR_BATCH_SIZE', 100),
            'registry_table' => 'vector_collection_registry',
            'install_extension' => (bool) env('VECTOR_PGVECTOR_INSTALL_EXTENSION', true),
            'ivfflat_lists' => 100,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Default embedding provider and provider-specific configuration.
    |
    */

    'embeddings' => [

        'default' => env('VECTOR_EMBEDDINGS_PROVIDER', 'prism'),

        'providers' => [

            'prism' => [
                'driver' => 'prism',
                'provider' => env('VECTOR_EMBEDDINGS_PRISM_PROVIDER', 'openai'),
                'model' => env('VECTOR_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
                'dimensions' => (int) env('VECTOR_EMBEDDINGS_DIMENSIONS', 1536),
                // null when unset or empty: Prism then uses its own provider key (e.g. OPENAI_API_KEY).
                'api_key' => env('VECTOR_EMBEDDINGS_API_KEY') ?: null,
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
