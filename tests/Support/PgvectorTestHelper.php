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

    /**
     * Collection names in the vector registry; [] when pgvector or the registry is not there.
     *
     * @return list<string>
     */
    public static function collectionNames(): array
    {
        try {
            return self::pdo()->query('SELECT name FROM vector_collection_registry')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Drops every collection not in $keep: its table and its registry row. Used after each test
     * so a failing test cannot leave collections behind.
     *
     * @param  list<string>  $keep
     */
    public static function dropCollectionsNotIn(array $keep): void
    {
        try {
            $pdo = self::pdo();
            $rows = $pdo->query('SELECT name, storage_identifier FROM vector_collection_registry')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (in_array($row['name'], $keep, true)) {
                    continue;
                }
                $pdo->exec('DROP TABLE IF EXISTS "'.str_replace('"', '""', (string) $row['storage_identifier']).'"');
                $pdo->prepare('DELETE FROM vector_collection_registry WHERE name = ?')->execute([$row['name']]);
            }
        } catch (Throwable) {
            // Best-effort cleanup: never fail a test from here.
        }

        // Aliases and migration records of the dropped collections (B5 tables; absent on an older registry).
        try {
            $pdo = self::pdo();
            $pdo->exec('DELETE FROM vector_collection_registry_aliases WHERE collection NOT IN (SELECT name FROM vector_collection_registry)');
            $pdo->exec('DELETE FROM vector_collection_registry_migrations m WHERE NOT EXISTS (SELECT 1 FROM vector_collection_registry r WHERE r.name IN (m.source, m.target))');
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }

    private static function pdo(): PDO
    {
        $config = self::connectionConfig();

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
            (string) $config['username'],
            (string) $config['password'],
            [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
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
