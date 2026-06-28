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
    | Each store adapter is configured here. Connection and index settings
    | are filled in when the pgvector adapter is implemented.
    |
    */

    'stores' => [

        'pgvector' => [
            'driver' => 'pgvector',
            'connection' => 'pgsql',
            'index' => 'hnsw',
            'metric' => 'cosine',
            'batch_size' => 100,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Default embedding provider and provider-specific configuration.
    | Prism wiring is applied when the embeddings adapter is implemented.
    |
    */

    'embeddings' => [

        'default' => 'prism',

        'providers' => [

            'prism' => [
                'driver' => 'prism',
                'model' => 'text-embedding-3-small',
                'dimensions' => 1536,
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

        'enabled' => true,

    ],

];
