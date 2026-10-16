<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

use AgenticGraph\Vector\Exceptions\VectorQueryException;
use Illuminate\Database\Connection;

/**
 * pgvector session tuning knobs passed via SearchQuery::$options, applied with SET LOCAL.
 *
 * Supported keys:
 * - hnsw.ef_search (int): HNSW recall/latency tradeoff
 * - hnsw.iterative_scan (off, relaxed_order, strict_order): pgvector 0.8+; a filtered search keeps scanning until it
 *   has topK hits. Default from the store config `iterative_scan` (relaxed_order) for searches with a filter.
 * - hnsw.max_scan_tuples (int): the most rows an iterative scan visits (store config `max_scan_tuples`, 20000)
 * - ivfflat.probes (int): IVFFlat recall/latency tradeoff
 */
final class PgVectorSearchOptions
{
    public const HNSW_EF_SEARCH = 'hnsw.ef_search';

    public const HNSW_ITERATIVE_SCAN = 'hnsw.iterative_scan';

    public const HNSW_MAX_SCAN_TUPLES = 'hnsw.max_scan_tuples';

    public const IVFFLAT_PROBES = 'ivfflat.probes';

    public const ITERATIVE_SCAN_MODES = ['off', 'relaxed_order', 'strict_order'];

    /**
     * The options of one search: the store config's iterative scan for a filtered search, then the query's own.
     *
     * @param  array<string, mixed>  $config  the store config
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function forSearch(array $config, array $options, bool $filtered): array
    {
        $defaults = $filtered ? [
            self::HNSW_ITERATIVE_SCAN => $config['iterative_scan'] ?? 'relaxed_order',
            self::HNSW_MAX_SCAN_TUPLES => $config['max_scan_tuples'] ?? 20000,
        ] : [];

        return [...$defaults, ...$options];
    }

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

        // off is pgvector's default, so it is not set (pgvector before 0.8 has no such setting).
        $iterative = $options[self::HNSW_ITERATIVE_SCAN] ?? 'off';

        if (! in_array($iterative, self::ITERATIVE_SCAN_MODES, true)) {
            throw new VectorQueryException('hnsw.iterative_scan must be one of '.implode(', ', self::ITERATIVE_SCAN_MODES).'.');
        }

        if ($iterative !== 'off') {
            $connection->statement("SET LOCAL hnsw.iterative_scan = {$iterative}");

            if (array_key_exists(self::HNSW_MAX_SCAN_TUPLES, $options)) {
                $connection->statement('SET LOCAL hnsw.max_scan_tuples = '.self::positiveInt($options[self::HNSW_MAX_SCAN_TUPLES]));
            }
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
