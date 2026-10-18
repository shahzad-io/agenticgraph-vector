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
            'search_path' => self::schema().',public',
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
            self::createSchema($pdo);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * This process's own schema. Two suites (or two runs) on one database never see each other's tables;
     * pgvector and unaccent stay in public, which is second on the search path.
     */
    public static function schema(): string
    {
        return 'agtest_'.getmypid();
    }

    /**
     * Creates this process's schema once (dropping a leftover from a reused pid) and drops it at exit.
     * ponytail: a killed run leaves its agtest_* schema behind; drop those by hand if they pile up.
     */
    private static function createSchema(PDO $pdo): void
    {
        static $created = false;

        if ($created) {
            return;
        }

        $schema = self::schema();
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS unaccent SCHEMA public');
        $pdo->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        $pdo->exec("CREATE SCHEMA {$schema}");
        $created = true;

        register_shutdown_function(static function () use ($schema): void {
            try {
                self::pdo()->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
            } catch (Throwable) {
                // Best-effort cleanup.
            }
        });
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

    /**
     * A raw connection on this process's search path.
     */
    public static function pdo(): PDO
    {
        $config = self::connectionConfig();

        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
            (string) $config['username'],
            (string) $config['password'],
            [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('SET search_path TO '.$config['search_path']);

        return $pdo;
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
