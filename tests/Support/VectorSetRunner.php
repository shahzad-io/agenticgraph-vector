<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Events\VectorEmbeddingCompleted;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use Generator;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Throwable;

/**
 * bin/vector-bench on a `vectors` set (H3, H4, H7): the vectors come from the export, so a run has no model, makes no
 * embedding call and needs no API key. It ingests the set once per build setting of the store's sweep file
 * (benchmarks/sweeps/<driver>.json), checks exact search against the ground truth (H3 self-check, stop rule S3), and
 * measures each search setting. A set with `built_from` is the filtered benchmark: one build with declared fields,
 * every filter variant of the sweep file at each selectivity.
 */
final class VectorSetRunner
{
    /** Queries of the self-check (per selectivity for the filtered set). */
    public const SELF_CHECK_QUERIES = 100;

    public const FILTERED_SELF_CHECK_QUERIES = 25;

    private BenchRunner $bench;

    private BenchStoreProbe $probe;

    private VectorStoreContract $store;

    private string $storeName = '';

    private string $out = '';

    private int $workers = 1;

    public static function isVectorSet(string $name, string $manifest = BenchDataset::MANIFEST): bool
    {
        return (self::readJson($manifest)['datasets'][$name]['kind'] ?? null) === 'vectors';
    }

    /**
     * The registry entry of a `vectors` set, its files checked against their pins (R3), and the entry that holds its
     * vectors (itself, or the set it is built from).
     *
     * @return array{name: string, meta: array<string, mixed>, vectors: array<string, mixed>, dir: string}
     */
    public static function load(string $name, string $manifest = BenchDataset::MANIFEST, string $exportDir = BenchDataset::EXPORT_DIR): array
    {
        $datasets = (array) self::readJson($manifest)['datasets'];
        /** @var array<string, mixed> $meta */
        $meta = $datasets[$name] ?? throw new RuntimeException("Unknown dataset [{$name}].");
        /** @var array<string, mixed> $vectors */
        $vectors = isset($meta['built_from']) ? $datasets[$meta['built_from']] : $meta;

        foreach (BenchDataset::REGISTRY_KEYS as $key => $allowed) {
            if (! isset($meta[$key]) || ($allowed !== null && ! in_array($meta[$key], $allowed, true))) {
                throw new RuntimeException("Dataset [{$name}] needs `{$key}` in datasets.json.");
            }
        }

        foreach ([...array_values((array) $vectors['files']), ...($vectors === $meta ? [] : array_values((array) $meta['files']))] as $file) {
            $path = "{$exportDir}/{$file['path']}";

            if (! is_file($path)) {
                throw new RuntimeException("Dataset file missing: {$path}. Run: python3 benchmarks/export-datasets.py {$name} (see benchmarks/README.md).");
            }

            if (($sha = hash_file('sha256', $path)) !== $file['sha256']) {
                throw new RuntimeException("Dataset file {$path} has sha256 {$sha}, the manifest pins {$file['sha256']}.");
            }
        }

        return ['name' => $name, 'meta' => $meta, 'vectors' => $vectors, 'dir' => "{$exportDir}/{$vectors['directory']}"];
    }

    /**
     * Rows of an exported vector file (H2 format: `<base>.f32` and `<base>.index.jsonl`), $chunk at a time.
     *
     * @return Generator<int, list<array{id: string, vector: list<float>}>>
     */
    public static function rows(string $base, int $dimensions, int $chunk = BenchRunner::INGEST_CHUNK): Generator
    {
        $vectors = fopen("{$base}.f32", 'rb') ?: throw new RuntimeException("Cannot open {$base}.f32.");
        $index = fopen("{$base}.index.jsonl", 'rb') ?: throw new RuntimeException("Cannot open {$base}.index.jsonl.");
        /** @var array{dimensions: int} $header */
        $header = json_decode((string) fgets($index), true, flags: JSON_THROW_ON_ERROR);

        if ($header['dimensions'] !== $dimensions) {
            throw new RuntimeException("{$base} holds {$header['dimensions']} dimensions, the registry says {$dimensions}.");
        }

        $rows = [];

        while (($line = fgets($index)) !== false) {
            $bytes = (string) fread($vectors, $dimensions * 4);
            $rows[] = ['id' => (string) json_decode($line, true, flags: JSON_THROW_ON_ERROR)['id'], 'vector' => array_values(unpack('g*', $bytes) ?: [])];

            if (count($rows) === $chunk) {
                yield $rows;
                $rows = [];
            }
        }

        if ($rows !== []) {
            yield $rows;
        }

        fclose($vectors);
        fclose($index);
    }

    /**
     * Whether an exact top 10 agrees with the ground truth top 10: every id outside the truth must be at least as near
     * as the truth's 10th (a tie), within float32 rounding.
     *
     * @param  list<string>  $hits
     * @param  list<string>  $truth
     * @param  callable(string): float  $distance  the distance of a row to the query (lower is nearer)
     */
    public static function agrees(array $hits, array $truth, callable $distance): bool
    {
        $truth = array_slice($truth, 0, BenchRunner::TOP_K);

        if (count($hits) < count($truth)) {
            return false;
        }

        $tenth = $distance($truth[count($truth) - 1]);

        foreach (array_diff($hits, $truth) as $id) {
            if ($distance($id) > $tenth + 1e-6 * max(1.0, abs($tenth))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Distance for the self-check: squared L2, or 1 - cosine similarity.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function distance(string $metric, array $a, array $b): float
    {
        $dot = $aa = $bb = $sq = 0.0;

        foreach ($a as $i => $x) {
            $dot += $x * $b[$i];
            $aa += $x * $x;
            $bb += $b[$i] * $b[$i];
            $sq += ($x - $b[$i]) ** 2;
        }

        return $metric === 'l2' ? $sq : 1 - $dot / max(sqrt($aa * $bb), 1e-12);
    }

    /**
     * @param  array<string, string|false|list<string|false>>  $options  getopt() with BenchRunner::OPTIONS
     */
    public function run(array $options): int
    {
        $runStarted = hrtime(true);
        $set = self::load((string) $options['dataset']);
        $meta = $set['meta'];
        $dimensions = (int) $set['vectors']['dimensions'];
        $metric = (string) $set['vectors']['metric'];
        $filtered = isset($meta['built_from']);
        $levels = array_map('intval', explode(',', (string) ($options['concurrency'] ?? '1,8')));
        $warmup = (int) ($options['warmup'] ?? 20);
        $this->out = rtrim((string) ($options['out'] ?? BenchRunner::defaultOut()), '/');
        $this->workers = max(1, (int) ($options['ingest-workers'] ?? 1));

        if (str_starts_with(($this->out[0] === '/' ? $this->out : getcwd().'/'.$this->out).'/', (string) realpath(BenchRunner::ROOT).'/')) {
            throw new RuntimeException("--out={$this->out} is inside the package repo. Use a folder outside version control.");
        }

        @mkdir($this->out.'/cache', 0777, true);

        // X3: no model and no key. The deterministic provider is configured only so the package boots; nothing calls it.
        BenchRunner::boot('deterministic', ['dimensions' => $dimensions]);
        $embeddingCalls = 0;
        Event::listen(VectorEmbeddingCompleted::class, static function () use (&$embeddingCalls): void {
            $embeddingCalls++;
        });

        $this->storeName = isset($options['store']) ? (string) $options['store'] : (string) config('vector.default');
        $probeClass = BenchRunner::probeClass($this->storeName);
        $this->probe = new $probeClass($this->storeName);
        $this->store = Vector::store($this->storeName);
        $this->bench = new BenchRunner;

        try {
            $this->store->healthCheck();
        } catch (Throwable $exception) {
            throw new RuntimeException("Store [{$this->storeName}] is not reachable: ".$exception->getMessage(), previous: $exception);
        }

        $driver = (string) (config("vector.stores.{$this->storeName}.driver") ?? $this->storeName);
        /** @var array{build: list<array{name: string, index_options: array<string, int>}>, search: list<array{name: string, ef: int|null}>, filtered: list<array{name: string, options?: array<string, mixed>, force_index?: bool, exact?: bool}>} $sweep */
        $sweep = self::readJson(isset($options['sweep']) ? (string) $options['sweep'] : BenchRunner::ROOT."/benchmarks/sweeps/{$driver}.json");
        $queries = [];

        foreach (self::rows("{$set['dir']}/queries", $dimensions) as $chunk) {
            foreach ($chunk as $row) {
                $queries[] = ['id' => 'q'.count($queries), 'text' => '', 'vector' => $row['vector'], 'filter' => null];
            }
        }

        $queries = array_slice($queries, 0, isset($options['queries']) ? (int) $options['queries'] : null);
        $truthFile = BenchDataset::EXPORT_DIR.'/'.($filtered ? $meta['files']['ground_truth']['path'] : $set['vectors']['files']['ground_truth']['path']);
        /** @var array{k: int, neighbors?: array<string, list<string>>, selectivities?: array<string, array{share: float, rows: int, neighbors: array<string, list<string>>}>} $truth */
        $truth = self::readJson($truthFile);
        $container = $this->probe->container();
        $builds = [];
        $filteredResults = null;
        $selfCheck = null;

        if (! $filtered) {
            $only = isset($options['builds']) ? explode(',', (string) $options['builds']) : null;

            foreach ($sweep['build'] as $build) {
                if ($only !== null && ! in_array($build['name'], $only, true)) {
                    continue;
                }

                $definition = static fn (string $collection): CollectionDefinition => new CollectionDefinition(name: $collection, dimensions: $dimensions, metric: $metric, index: 'hnsw', indexOptions: $build['index_options']);
                $builds[] = $this->withCollection($set, $definition, null, function (string $collection, array $ingest) use ($build, $sweep, $queries, $truth, $levels, $warmup, $metric, $set, $dimensions, &$selfCheck): array {
                    // H3: before the sweep, the store's exact search must agree with the ground truth (S3).
                    $selfCheck ??= $this->selfCheck($collection, array_slice($queries, 0, self::SELF_CHECK_QUERIES), (array) $truth['neighbors'], $metric, "{$set['dir']}/base", $dimensions);
                    $searches = [];

                    foreach ($sweep['search'] as $search) {
                        self::say("{$build['name']}, {$search['name']}");
                        $options = $this->probe->searchOptions($search['ef'], null);
                        $measured = $this->measure($collection, $queries, $options, false, null, $levels, $warmup);
                        $deep = $this->probe->searchOptions(max($search['ef'] ?? 0, 100), null);
                        $ranked100 = [];

                        foreach ($queries as $query) {
                            $ranked100[$query['id']] = BenchRunner::searchIds(['store' => $this->storeName, 'collection' => $collection, 'top_k' => 100, 'mode' => 'dense', 'field' => null, 'options' => $deep], $query);
                        }

                        $searches[] = [
                            'name' => $search['name'],
                            'options' => $options,
                            'recall_at_10' => RetrievalMetrics::recallAtK(self::truthAt((array) $truth['neighbors'], 10, $measured['ranked']), $measured['ranked'], 10),
                            'recall_at_100' => RetrievalMetrics::recallAtK(self::truthAt((array) $truth['neighbors'], 100, $ranked100), $ranked100, 100),
                            'below_take' => $measured['below_take'],
                            'concurrency' => $measured['concurrency'],
                        ];
                    }

                    return ['name' => $build['name'], 'index_options' => $build['index_options'], ...$ingest, 'searches' => $searches];
                });
            }
        } else {
            $field = (string) ($meta['field'] ?? 'tenant');
            $definition = static fn (string $collection): CollectionDefinition => new CollectionDefinition(name: $collection, dimensions: $dimensions, metric: $metric, filterable: [$field => 'keyword', 'rank' => 'integer']);
            /** @var list<array{0: string, 1: int}> $fields */
            $fields = self::readJson(BenchDataset::EXPORT_DIR.'/'.$meta['files']['fields']['path']);

            $builds[] = $this->withCollection($set, $definition, $fields, function (string $collection, array $ingest) use ($sweep, $queries, $truth, $warmup, $metric, $set, $dimensions, $field, &$selfCheck, &$filteredResults): array {
                $filteredResults = [];
                $selfCheck = ['queries' => 0, 'mismatches' => []];

                foreach ((array) $truth['selectivities'] as $value => $selectivity) {
                    $filteredQueries = array_map(static fn (array $query): array => ['filter' => $value] + $query, $queries);
                    $check = $this->selfCheck($collection, array_slice($filteredQueries, 0, self::FILTERED_SELF_CHECK_QUERIES), $selectivity['neighbors'], $metric, "{$set['dir']}/base", $dimensions, $field);
                    $selfCheck['queries'] += $check['queries'];
                    $variants = [];

                    foreach ($sweep['filtered'] as $variant) {
                        self::say("{$field} = {$value} ({$selectivity['share']}), {$variant['name']}");
                        $options = ($variant['exact'] ?? false) ? $this->probe->exactSearch($collection) : ($variant['options'] ?? []);
                        $measured = $this->measure($collection, $filteredQueries, $options, $variant['force_index'] ?? false, $field, [1], $warmup);
                        $hits = array_map('count', $measured['ranked']);
                        $variants[] = [
                            'name' => $variant['name'],
                            'options' => $options,
                            'force_index' => $variant['force_index'] ?? false,
                            'recall_at_10' => RetrievalMetrics::recallAtK(self::truthAt($selectivity['neighbors'], 10, $measured['ranked']), $measured['ranked'], 10),
                            'hits_mean' => round(array_sum($hits) / max(count($hits), 1), 2),
                            'hits_min' => $hits === [] ? 0 : min($hits),
                            'below_take' => $measured['below_take'],
                            'p50_ms' => $measured['concurrency'][1]['p50_ms'],
                            'p95_ms' => $measured['concurrency'][1]['p95_ms'],
                        ];
                    }

                    $filteredResults[] = ['value' => $value, 'share' => $selectivity['share'], 'rows' => $selectivity['rows'], 'variants' => $variants];
                }

                return ['name' => 'store default', 'index_options' => [], ...$ingest, 'searches' => []];
            });
        }

        if ($embeddingCalls !== 0) {
            throw new RuntimeException("A vectors run made {$embeddingCalls} embedding calls (exit X3).");
        }

        $report = [
            'schema' => 1,
            'kind' => 'vectors',
            'created_at' => date(DATE_ATOM),
            'dataset' => [
                'name' => $set['name'],
                'description' => $meta['description'],
                'license' => $meta['license'],
                'license_note' => $meta['license_note'],
                'citation' => $meta['citation'],
                'built_from' => $meta['built_from'] ?? null,
                'source' => $set['vectors']['source'],
                'files' => array_map(static fn (array $file): string => $file['sha256'], [...(array) $set['vectors']['files'], ...($filtered ? (array) $meta['files'] : [])]),
                'ground_truth' => $meta['ground_truth'] ?? null,
                'primary_metric' => $meta['primary_metric'],
            ],
            'sizes' => ['rows' => $builds[0]['documents'] ?? 0, 'queries' => count($queries), 'dimensions' => $dimensions, 'metric' => $metric, 'top_k' => BenchRunner::TOP_K, 'warmup' => $warmup],
            'store' => [
                'name' => $this->storeName,
                'driver' => $driver,
                'engine' => $this->probe->engineVersion(),
                'capabilities' => array_map(static fn (StoreCapability $capability): string => $capability->value, $this->store->capabilities()->capabilities),
            ],
            'embedding_calls' => $embeddingCalls,
            'self_check' => $selfCheck,
            'builds' => $builds,
            'filtered' => $filteredResults,
            'infrastructure' => ['php' => PHP_VERSION, 'os' => php_uname('s').' '.php_uname('r'), 'machine' => BenchRunner::machine($container), 'container' => $container],
            'runtime_ms' => (int) round((hrtime(true) - $runStarted) / 1_000_000),
        ];

        $base = "{$this->out}/{$set['name']}.{$this->storeName}.".date('Ymd-His');
        file_put_contents("{$base}.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        file_put_contents("{$base}.md", self::markdown($report));
        fwrite(STDOUT, self::markdown($report)."\nReport: {$base}.json\n");

        return 0;
    }

    /**
     * Create a collection, ingest the set, wait for the index, run $body, and drop the collection.
     *
     * @param  array{name: string, meta: array<string, mixed>, vectors: array<string, mixed>, dir: string}  $set
     * @param  callable(string): CollectionDefinition  $definition
     * @param  list<array{0: string, 1: int}>|null  $fields  per row: tenant and rank (the filtered set)
     * @param  callable(string, array<string, mixed>): array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function withCollection(array $set, callable $definition, ?array $fields, callable $body): array
    {
        $collection = 'bench_'.substr(hash('sha256', $set['name'].getmypid().microtime()), 0, 12);
        $this->store->createCollection($definition($collection));

        try {
            $started = hrtime(true);
            $job = ['store' => $this->storeName, 'collection' => $collection, 'base' => "{$set['dir']}/base", 'dimensions' => (int) $set['vectors']['dimensions'], 'fields' => $fields];
            $rows = $this->workers === 1 ? self::ingest($job, 0, 1) : $this->ingestInWorkers($job);
            $ingestMs = self::ms($started);
            $started = hrtime(true);
            $this->probe->waitUntilReady($collection);
            $readyMs = self::ms($started);
            $started = hrtime(true);
            $rebuildMs = $this->probe->rebuildIndex($collection) ? self::ms($started) : null;
            $ingest = [
                'documents' => $rows,
                'ingest_workers' => $this->workers,
                'ingest_ms' => $ingestMs,
                'docs_per_second' => round($rows / max($ingestMs / 1000, 1e-9), 1),
                'ready_wait_ms' => $readyMs,
                'rebuild_ms' => $rebuildMs,
                // pgvector: REINDEX of the full table; Qdrant: the wait after the last write until the index is built.
                'build_ms' => $rebuildMs ?? $readyMs,
                'index' => $this->probe->index($collection),
                'sizes' => $this->probe->sizes($collection),
            ];
            self::say("{$collection}: {$rows} rows, ingest {$ingestMs} ms, build ".$ingest['build_ms'].' ms, '.$ingest['index']);

            $result = $body($collection, $ingest);
            $container = $this->probe->container();
            $usage = $container === null ? '' : trim((string) shell_exec('docker stats --no-stream --format "{{.MemUsage}}" '.escapeshellarg($container).' 2>/dev/null'));

            return $result + ['memory' => $usage === '' ? null : $usage];
        } finally {
            $this->store->deleteCollection($collection);
        }
    }

    /**
     * Upsert every $of-th chunk of the set, starting at chunk $index (one ingest worker); returns the rows upserted.
     *
     * @param  array{store: string, collection: string, base: string, dimensions: int, fields: list<array{0: string, 1: int}>|null}  $job
     */
    public static function ingest(array $job, int $index, int $of): int
    {
        $store = Vector::store($job['store']);
        $rows = 0;

        foreach (self::rows($job['base'], $job['dimensions']) as $number => $chunk) {
            if ($number % $of !== $index) {
                continue;
            }

            $store->upsert($job['collection'], array_map(static fn (array $row): Document => new Document(
                $row['id'], '', $job['fields'] === null ? [] : ['tenant' => $job['fields'][(int) $row['id']][0], 'rank' => $job['fields'][(int) $row['id']][1]], $row['vector'],
            ), $chunk));
            $rows += count($chunk);

            if (intdiv($number, $of) % 100 === 99) {
                self::say("Worker {$index}: {$rows} rows");
            }
        }

        return $rows;
    }

    /**
     * Ingest worker process (bin/vector-bench --ingest-child=<job> --slice=i/N): prints its row count.
     */
    public static function ingestChild(string $jobFile, string $slice): void
    {
        /** @var array{store: string, collection: string, base: string, dimensions: int, fields: list<array{0: string, 1: int}>|null} $job */
        $job = self::readJson($jobFile);
        [$index, $of] = array_map('intval', explode('/', $slice));
        BenchRunner::boot('deterministic', ['dimensions' => $job['dimensions']]);
        fwrite(STDOUT, self::ingest($job, $index, $of)."\n");
    }

    /**
     * H10: ingest with $this->workers processes, each upserting every N-th chunk (the store batches as usual).
     *
     * @param  array{store: string, collection: string, base: string, dimensions: int, fields: list<array{0: string, 1: int}>|null}  $job
     */
    private function ingestInWorkers(array $job): int
    {
        $jobFile = "{$this->out}/cache/ingest-{$job['collection']}.json";
        file_put_contents($jobFile, json_encode($job, JSON_THROW_ON_ERROR));
        $children = [];
        $outputs = [];

        for ($i = 0; $i < $this->workers; $i++) {
            $children[] = proc_open([PHP_BINARY, BenchRunner::BIN, "--ingest-child={$jobFile}", "--slice={$i}/{$this->workers}"], [1 => ['pipe', 'w'], 2 => STDERR], $pipes)
                ?: throw new RuntimeException('Cannot start an ingest process.');
            $outputs[] = $pipes[1];
        }

        $rows = 0;

        foreach ($children as $i => $child) {
            $rows += (int) stream_get_contents($outputs[$i]);

            if (proc_close($child) !== 0) {
                throw new RuntimeException("Ingest worker {$i} failed; see its output above.");
            }
        }

        unlink($jobFile);

        return $rows;
    }

    /**
     * Timed searches through child processes at each concurrency level (BenchRunner::runLevel), after a warmup in this
     * process. The ranking is the first level's.
     *
     * @param  list<array{id: string, text: string, vector: list<float>, filter: string|int|null}>  $queries
     * @param  array<string, mixed>  $options
     * @param  list<int>  $levels
     * @return array{ranked: array<string, list<string>>, below_take: int, concurrency: array<int, array<string, int|float|null>>}
     */
    private function measure(string $collection, array $queries, array $options, bool $forceIndex, ?string $field, array $levels, int $warmup): array
    {
        $job = ['store' => $this->storeName, 'collection' => $collection, 'top_k' => BenchRunner::TOP_K, 'mode' => 'dense', 'field' => $field, 'options' => $options, 'force_index' => $forceIndex, 'queries' => $queries];
        $forceIndex && $this->probe->forceIndex(true);

        try {
            for ($i = 0; $i < $warmup; $i++) {
                BenchRunner::searchIds($job, $queries[$i % count($queries)]);
            }
        } finally {
            $forceIndex && $this->probe->forceIndex(false);
        }

        $jobFile = "{$this->out}/cache/job-{$collection}.json";
        file_put_contents($jobFile, json_encode($job, JSON_THROW_ON_ERROR));
        $runs = [];
        $ranked = null;

        foreach ($levels as $level) {
            $lines = $this->bench->runLevel($jobFile, "{$this->out}/cache/results-{$collection}-{$level}", $level);

            if (count($lines) !== count($queries)) {
                throw new RuntimeException("Concurrency {$level}: ".count($lines).' of '.count($queries).' queries returned.');
            }

            $latencies = array_column($lines, 'ms');
            $wall = max(array_column($lines, 'at')) - min(array_map(static fn (array $line): float => $line['at'] - $line['ms'] / 1000, $lines));
            $runs[$level] = [
                'qps' => round(count($lines) / max($wall, 1e-9), 1),
                'p50_ms' => BenchReport::percentile($latencies, 50),
                'p95_ms' => BenchReport::percentile($latencies, 95),
                'p99_ms' => BenchReport::percentile($latencies, 99),
            ];
            $ranked ??= array_column($lines, 'ids', 'id');
        }

        unlink($jobFile);

        return ['ranked' => (array) $ranked, 'below_take' => count(array_filter((array) $ranked, static fn (array $ids): bool => count($ids) < BenchRunner::TOP_K)), 'concurrency' => $runs];
    }

    /**
     * H3 self-check: the store's exact top 10 against the ground truth, ties allowed. Throws on a mismatch (S3).
     *
     * @param  list<array{id: string, text: string, vector: list<float>, filter: string|int|null}>  $queries
     * @param  array<string, list<string>>  $truth
     * @return array{queries: int, mismatches: list<string>}
     */
    private function selfCheck(string $collection, array $queries, array $truth, string $metric, string $base, int $dimensions, ?string $field = null): array
    {
        $exact = $this->probe->exactSearch($collection);
        $handle = fopen("{$base}.f32", 'rb') ?: throw new RuntimeException("Cannot open {$base}.f32.");
        $mismatches = [];

        foreach ($queries as $query) {
            $hits = BenchRunner::searchIds(['store' => $this->storeName, 'collection' => $collection, 'top_k' => BenchRunner::TOP_K, 'mode' => 'dense', 'field' => $field, 'options' => $exact], $query);
            $distance = static function (string $id) use ($handle, $dimensions, $metric, $query): float {
                fseek($handle, (int) $id * $dimensions * 4);

                return self::distance($metric, $query['vector'], array_values(unpack('g*', (string) fread($handle, $dimensions * 4)) ?: []));
            };

            if (! self::agrees($hits, $truth[$query['id']], $distance)) {
                $mismatches[] = $query['id'];
            }
        }

        fclose($handle);
        self::say('Self-check: '.(count($queries) - count($mismatches)).' of '.count($queries).' exact searches agree with the ground truth'.($field === null ? '' : " ({$field} = {$queries[0]['filter']})"));

        if ($mismatches !== []) {
            throw new RuntimeException('Self-check failed (stop rule S3): exact search disagrees with the ground truth on '.implode(', ', array_slice($mismatches, 0, 10)).'.');
        }

        return ['queries' => count($queries), 'mismatches' => []];
    }

    /**
     * The ground truth top $k of the queries that ran (recall averages over the truth's queries).
     *
     * @param  array<string, list<string>>  $neighbors
     * @param  array<string, list<string>>  $ranked
     * @return array<string, list<string>>
     */
    private static function truthAt(array $neighbors, int $k, array $ranked): array
    {
        return array_map(static fn (array $ids): array => array_slice($ids, 0, $k), array_intersect_key($neighbors, $ranked));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function markdown(array $report): string
    {
        $na = BenchReport::NA;
        $mb = static fn (?int $bytes): string => $bytes === null ? $na : number_format($bytes / 1024 ** 2, 0).' MiB';
        $lines = [
            "# vector-bench: {$report['dataset']['name']} on {$report['store']['name']}",
            '',
            "- Created: {$report['created_at']}",
            "- Dataset: {$report['dataset']['description']} ({$report['dataset']['license']}); ground truth: ".($report['dataset']['ground_truth'] ?? $na),
            sprintf('- Size: %d rows, %d queries, %d dimensions, %s', $report['sizes']['rows'], $report['sizes']['queries'], $report['sizes']['dimensions'], $report['sizes']['metric']),
            "- Store: {$report['store']['name']} ({$report['store']['driver']}), engine ".($report['store']['engine'] ?? $na),
            '- Machine: '.BenchReport::machineLine($report['infrastructure']['machine']),
            "- Embedding calls: {$report['embedding_calls']} (no model, no API key)",
            "- Self-check: {$report['self_check']['queries']} exact searches agree with the ground truth",
            sprintf('- Run time: %.1f minutes', $report['runtime_ms'] / 60000),
            '',
        ];

        foreach ($report['builds'] as $build) {
            $lines[] = sprintf('## Build %s: ingest %.1f s (%.0f rows/s), build %.1f s, size %s', $build['name'], $build['ingest_ms'] / 1000, $build['docs_per_second'], $build['build_ms'] / 1000, $mb($build['sizes']['total_bytes'] ?? null));
            $lines[] = '';
            $lines[] = "Index: `{$build['index']}`. Index size ".$mb($build['sizes']['index_bytes'] ?? null).'. Memory after the searches: '.($build['memory'] ?? $na).'.';
            $lines[] = '';

            if ($build['searches'] !== []) {
                $lines[] = '| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |';
                $lines[] = '|---|---|---|---|---|---|---|---|';

                foreach ($build['searches'] as $search) {
                    $c1 = $search['concurrency'][1] ?? $search['concurrency']['1'] ?? [];
                    $c8 = $search['concurrency'][8] ?? $search['concurrency']['8'] ?? [];
                    $lines[] = sprintf('| %s | %.4f | %.4f | %s | %s | %s | %s | %s |', $search['name'], $search['recall_at_10'], $search['recall_at_100'],
                        $c1['p50_ms'] ?? $na, $c1['p95_ms'] ?? $na, $c1['p99_ms'] ?? $na, $c1['qps'] ?? $na, $c8['qps'] ?? $na);
                }

                $lines[] = '';
            }
        }

        foreach ($report['filtered'] ?? [] as $cell) {
            $lines[] = sprintf('## Filter tenant = %s (%s of the rows, %d rows)', $cell['value'], $cell['share'], $cell['rows']);
            $lines[] = '';
            $lines[] = '| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |';
            $lines[] = '|---|---|---|---|---|---|';

            foreach ($cell['variants'] as $variant) {
                $lines[] = sprintf('| %s | %.4f | %.2f | %d | %s | %s |', $variant['name'], $variant['recall_at_10'], $variant['hits_mean'], $variant['below_take'], $variant['p50_ms'], $variant['p95_ms']);
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<mixed>
     */
    private static function readJson(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("No file at {$file}.");
        }

        return (array) json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function ms(int $startedHrtime): int
    {
        return (int) round((hrtime(true) - $startedHrtime) / 1_000_000);
    }

    private static function say(string $message): void
    {
        fwrite(STDERR, '['.date('H:i:s')."] {$message}\n");
    }
}
