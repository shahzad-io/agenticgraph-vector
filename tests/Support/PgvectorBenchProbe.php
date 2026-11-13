<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Stores\PgVector\PgVectorSearchOptions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The pgvector side of the benchmark: the Postgres connection (VECTOR_PG_* env), table and index sizes, REINDEX,
 * the HNSW search settings, and exact search by dropping the HNSW index.
 */
final class PgvectorBenchProbe implements BenchStoreProbe
{
    public function __construct(private readonly string $store) {}

    public static function configure(Repository $config, string $store): void
    {
        // public, not the per-process test schema: the query children and later runs share the collections.
        $config->set('database.default', $store);
        $config->set("database.connections.{$store}", ['search_path' => 'public'] + PgvectorTestHelper::connectionConfig());
        $config->set("vector.stores.{$store}.connection", $store);
    }

    public function engineVersion(): ?string
    {
        // The memory settings move build and search times, so a report names them.
        $settings = implode(', ', array_map(fn (string $name): string => "{$name} ".$this->db()->scalar("SHOW {$name}"), ['shared_buffers', 'maintenance_work_mem', 'max_parallel_maintenance_workers']));

        return 'pgvector '.$this->db()->scalar("SELECT extversion FROM pg_extension WHERE extname = 'vector'").', Postgres '.$this->db()->scalar('SHOW server_version')." ({$settings})";
    }

    public function sizes(string $collection): ?array
    {
        $storage = $this->storage($collection);
        /** @var object{t: int|string, i: int|string, a: int|string} $sizes */
        $sizes = $this->db()->selectOne('SELECT pg_table_size(?::regclass) AS t, pg_indexes_size(?::regclass) AS i, pg_total_relation_size(?::regclass) AS a', [$storage, $storage, $storage]);

        return ['table_bytes' => (int) $sizes->t, 'index_bytes' => (int) $sizes->i, 'total_bytes' => (int) $sizes->a];
    }

    public function waitUntilReady(string $collection): void {}

    public function rebuildIndex(string $collection): bool
    {
        // REINDEX, not the contract's CREATE INDEX CONCURRENTLY: the build time of the index alone (HNSW grows during inserts).
        $this->db()->statement('REINDEX INDEX '.$this->storage($collection).'_embedding_idx');

        return true;
    }

    public function index(string $collection): ?string
    {
        return (string) $this->db()->scalar('SELECT pg_get_indexdef(to_regclass(?))', [$this->storage($collection).'_embedding_idx']) ?: 'none (exact scan)';
    }

    public function container(): ?string
    {
        return trim((string) shell_exec('docker ps --filter publish='.escapeshellarg((string) PgvectorTestHelper::connectionConfig()['port']).' --format "{{.Names}}" 2>/dev/null')) ?: null;
    }

    public function searchOptions(?int $ef, ?string $iterativeScan): array
    {
        if ($iterativeScan !== null && ! in_array($iterativeScan, PgVectorSearchOptions::ITERATIVE_SCAN_MODES, true)) {
            throw new RuntimeException('--iterative-scan takes '.implode(', ', PgVectorSearchOptions::ITERATIVE_SCAN_MODES).'.');
        }

        return ($iterativeScan === null ? [] : [PgVectorSearchOptions::HNSW_ITERATIVE_SCAN => $iterativeScan])
            + ($ef === null ? [] : [PgVectorSearchOptions::HNSW_EF_SEARCH => $ef]);
    }

    public function exactSearch(string $collection): array
    {
        // The store's exact option: index scans off for each search, the index kept.
        return [PgVectorSearchOptions::EXACT => true];
    }

    public function forceIndex(bool $on): void
    {
        // Session level, on the connection the store searches on: rule L2 sets this per search only without a filter.
        $this->db()->statement('SET enable_seqscan = '.($on ? 'off' : 'on'));
    }

    private function db(): Connection
    {
        return DB::connection((string) config("vector.stores.{$this->store}.connection"));
    }

    private function storage(string $collection): string
    {
        $name = Vector::store($this->store)->describeCollection($collection)['collection'];

        return (string) $this->db()->table((string) config("vector.stores.{$this->store}.registry_table", 'vector_collection_registry'))
            ->where('name', $name)->value('storage_identifier');
    }
}
