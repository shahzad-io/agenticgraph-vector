<?php

use Illuminate\Support\Arr;

/**
 * Loads config/vector.php with one env value set (or unset) and returns the value at $path.
 */
function vectorConfigValue(string $path, string $env, ?string $value): mixed
{
    $value === null ? putenv($env) : putenv("{$env}={$value}");

    try {
        $config = require __DIR__.'/../../config/vector.php';
    } finally {
        putenv($env);
    }

    return Arr::get($config, $path);
}

it('reads each F7 key from its env value', function (string $path, string $env, string $value, mixed $expected) {
    expect(vectorConfigValue($path, $env, $value))->toBe($expected);
})->with([
    'embeddings.default' => ['embeddings.default', 'VECTOR_EMBEDDINGS_PROVIDER', 'deterministic', 'deterministic'],
    'prism.provider' => ['embeddings.providers.prism.provider', 'VECTOR_EMBEDDINGS_PRISM_PROVIDER', 'mistral', 'mistral'],
    'prism.model' => ['embeddings.providers.prism.model', 'VECTOR_EMBEDDINGS_MODEL', 'text-embedding-3-large', 'text-embedding-3-large'],
    'prism.dimensions (cast to int)' => ['embeddings.providers.prism.dimensions', 'VECTOR_EMBEDDINGS_DIMENSIONS', '3072', 3072],
    'prism.api_key' => ['embeddings.providers.prism.api_key', 'VECTOR_EMBEDDINGS_API_KEY', 'sk-test-only', 'sk-test-only'],
    'prism.dimensions empty is null' => ['embeddings.providers.prism.dimensions', 'VECTOR_EMBEDDINGS_DIMENSIONS', '', null],
    'prism.api_key empty is null' => ['embeddings.providers.prism.api_key', 'VECTOR_EMBEDDINGS_API_KEY', '', null],
    'pgvector.batch_size' => ['stores.pgvector.batch_size', 'VECTOR_PGVECTOR_BATCH_SIZE', '250', 250],
    'pgvector.install_extension' => ['stores.pgvector.install_extension', 'VECTOR_PGVECTOR_INSTALL_EXTENSION', 'false', false],
    'events.enabled' => ['events.enabled', 'VECTOR_EVENTS_ENABLED', 'false', false],
]);

it('keeps the current default when the env value is unset', function (string $path, string $env, mixed $default) {
    expect(vectorConfigValue($path, $env, null))->toBe($default);
})->with([
    'embeddings.default' => ['embeddings.default', 'VECTOR_EMBEDDINGS_PROVIDER', 'prism'],
    'prism.provider has no vendor default' => ['embeddings.providers.prism.provider', 'VECTOR_EMBEDDINGS_PRISM_PROVIDER', null],
    'prism.model has no vendor default' => ['embeddings.providers.prism.model', 'VECTOR_EMBEDDINGS_MODEL', null],
    'prism.dimensions null asks the provider' => ['embeddings.providers.prism.dimensions', 'VECTOR_EMBEDDINGS_DIMENSIONS', null],
    'prism.api_key' => ['embeddings.providers.prism.api_key', 'VECTOR_EMBEDDINGS_API_KEY', null],
    'pgvector.batch_size' => ['stores.pgvector.batch_size', 'VECTOR_PGVECTOR_BATCH_SIZE', 100],
    'pgvector.install_extension' => ['stores.pgvector.install_extension', 'VECTOR_PGVECTOR_INSTALL_EXTENSION', true],
    'events.enabled' => ['events.enabled', 'VECTOR_EVENTS_ENABLED', true],
]);
