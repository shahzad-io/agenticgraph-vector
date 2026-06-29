<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

use Illuminate\Database\Connection;

/**
 * pgvector session tuning knobs passed via SearchQuery::$options.
 *
 * Supported keys:
 * - hnsw.ef_search (int): SET LOCAL hnsw.ef_search — HNSW recall/latency tradeoff
 * - ivfflat.probes (int): SET LOCAL ivfflat.probes — IVFFlat recall/latency tradeoff
 */
final class PgVectorSearchOptions
{
    public const HNSW_EF_SEARCH = 'hnsw.ef_search';

    public const IVFFLAT_PROBES = 'ivfflat.probes';

    /**
     * @param  array<string, mixed>  $options
     */
    public static function apply(Connection $connection, array $options): void
    {
        if (array_key_exists(self::HNSW_EF_SEARCH, $options)) {
            $connection->statement(
                'SET LOCAL hnsw.ef_search = '.self::positiveInt($options[self::HNSW_EF_SEARCH]),
            );
        }

        if (array_key_exists(self::IVFFLAT_PROBES, $options)) {
            $connection->statement(
                'SET LOCAL ivfflat.probes = '.self::positiveInt($options[self::IVFFLAT_PROBES]),
            );
        }
    }

    private static function positiveInt(mixed $value): int
    {
        return max(1, (int) $value);
    }
}
