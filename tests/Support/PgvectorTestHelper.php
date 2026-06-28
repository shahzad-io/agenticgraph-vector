<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use PDO;
use Throwable;

final class PgvectorTestHelper
{
    /**
     * @return array<string, mixed>
     */
    public static function connectionConfig(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => self::env('VECTOR_PG_HOST', '127.0.0.1'),
            'port' => self::env('VECTOR_PG_PORT', '5433'),
            'database' => self::env('VECTOR_PG_DATABASE', 'agenticgraph_vector_test'),
            'username' => self::env('VECTOR_PG_USERNAME', 'agenticgraph'),
            'password' => self::env('VECTOR_PG_PASSWORD', 'agenticgraph'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    public static function isAvailable(): bool
    {
        if (self::env('VECTOR_PGVECTOR_INTEGRATION', 'true') === 'false') {
            return false;
        }

        try {
            $config = self::connectionConfig();
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'],
                $config['port'],
                $config['database'],
            );

            $pdo = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
                PDO::ATTR_TIMEOUT => 2,
            ]);
            $pdo->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function skipReason(): string
    {
        if (self::env('VECTOR_PGVECTOR_INTEGRATION', 'true') === 'false') {
            return 'Skipping: VECTOR_PGVECTOR_INTEGRATION=false (pgvector integration disabled).';
        }

        $config = self::connectionConfig();

        return sprintf(
            'Skipping: pgvector not available at %s:%s/%s.',
            $config['host'],
            $config['port'],
            $config['database'],
        );
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }
}
