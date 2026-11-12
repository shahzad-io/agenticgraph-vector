<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use AgenticGraph\Vector\Facades\Vector;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Qdrant side of the benchmark: the url (VECTOR_QDRANT_URL, else the docker-compose port 16333), the wait for
 * the optimizer to finish indexing, the HNSW config, and `hnsw_ef` and `exact` as search options. Qdrant's REST API
 * gives no bytes per collection, so sizes are not available.
 */
final class QdrantBenchProbe implements BenchStoreProbe
{
    /** How long waitUntilReady() waits for a collection to turn green. */
    private const READY_SECONDS = 600;

    public function __construct(private readonly string $store) {}

    public static function configure(Repository $config, string $store): void
    {
        if ((string) $config->get("vector.stores.{$store}.url") === '') {
            $config->set("vector.stores.{$store}.url", QdrantTestHelper::url());
        }
    }

    public function engineVersion(): ?string
    {
        return 'qdrant '.$this->http()->get('/')->throw()->json('version');
    }

    public function sizes(string $collection): ?array
    {
        return null;
    }

    public function waitUntilReady(string $collection): void
    {
        $deadline = microtime(true) + self::READY_SECONDS;

        while ($this->info($collection)['status'] !== 'green') {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Qdrant collection [{$collection}] was not green after ".self::READY_SECONDS.' seconds.');
            }

            usleep(250_000);
        }
    }

    public function rebuildIndex(string $collection): bool
    {
        return false;
    }

    public function index(string $collection): ?string
    {
        $info = $this->info($collection);
        $hnsw = (array) ($info['config']['hnsw_config'] ?? []);

        return sprintf('hnsw m=%d ef_construct=%d full_scan_threshold=%d, %d of %d points indexed',
            $hnsw['m'] ?? 0, $hnsw['ef_construct'] ?? 0, $hnsw['full_scan_threshold'] ?? 0, $info['indexed_vectors_count'] ?? 0, $info['points_count'] ?? 0);
    }

    public function container(): ?string
    {
        $port = (string) (parse_url((string) config("vector.stores.{$this->store}.url"), PHP_URL_PORT) ?? 6333);

        return trim((string) shell_exec('docker ps --filter publish='.escapeshellarg($port).' --format "{{.Names}}" 2>/dev/null')) ?: null;
    }

    public function searchOptions(?int $ef, ?string $iterativeScan): array
    {
        if ($iterativeScan !== null) {
            throw new RuntimeException("Store [{$this->store}] (Qdrant) has no iterative scan setting.");
        }

        return $ef === null ? [] : ['hnsw_ef' => $ef];
    }

    public function exactSearch(string $collection): array
    {
        return ['exact' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function info(string $collection): array
    {
        $name = config("vector.stores.{$this->store}.collection_prefix", '').Vector::store($this->store)->describeCollection($collection)['collection'];

        return (array) $this->http()->get('/collections/'.rawurlencode($name))->throw()->json('result');
    }

    private function http(): PendingRequest
    {
        $key = (string) config("vector.stores.{$this->store}.api_key", '');

        return Http::baseUrl(rtrim((string) config("vector.stores.{$this->store}.url"), '/'))->acceptJson()->timeout(30)
            ->withHeaders($key === '' ? [] : ['api-key' => $key]);
    }
}
