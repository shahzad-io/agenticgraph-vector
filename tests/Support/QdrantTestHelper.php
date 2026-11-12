<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

/**
 * The Qdrant of docker-compose.yml: VECTOR_QDRANT_URL, else localhost on VECTOR_QDRANT_PORT (16333).
 * Probed with a plain stream, so a test's Http::fake() does not answer for it.
 */
final class QdrantTestHelper
{
    public static function url(): string
    {
        return self::env('VECTOR_QDRANT_URL', 'http://127.0.0.1:'.self::env('VECTOR_QDRANT_PORT', '16333'));
    }

    public static function isAvailable(): bool
    {
        if (self::env('VECTOR_QDRANT_INTEGRATION', 'true') === 'false') {
            return false;
        }

        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);

        return str_contains((string) @file_get_contents(rtrim(self::url(), '/').'/', false, $context), '"version"');
    }

    public static function skipReason(): string
    {
        return self::env('VECTOR_QDRANT_INTEGRATION', 'true') === 'false'
            ? 'Skipping: VECTOR_QDRANT_INTEGRATION=false (Qdrant integration disabled).'
            : 'Skipping: Qdrant not available at '.self::url().' (docker compose up -d qdrant).';
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
