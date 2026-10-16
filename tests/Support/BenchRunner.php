<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use AgenticGraph\Vector\Events\VectorEmbeddingCompleted;
use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\VectorServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Foundation\Application;
use Prism\Prism\PrismServiceProvider;
use RuntimeException;
use Throwable;

/**
 * bin/vector-bench: the retrieval benchmark on pgvector (dev only, Testbench based). See benchmarks/README.md.
 * A RuntimeException is a setup error (exit 2); run() returns 0 when done and 1 on a quality drop.
 */
final class BenchRunner
{
    public const ROOT = __DIR__.'/../..';

    public const BIN = self::ROOT.'/bin/vector-bench';

    public const TOP_K = 10;

    public const MODES = ['dense'];

    public const OPTIONS = [
        'dataset:', 'model:', 'dimensions:', 'mode:', 'concurrency:', 'warmup:', 'queries:', 'docs:',
        'truncate:', 'out:', 'compare::', 'tolerance:', 'price-per-1m-tokens:', 'report:', 'help',
        'child:', 'slice:', 'results:',
    ];

    public const USAGE = <<<'TXT'
    Usage: bin/vector-bench --dataset=<scifact|wixqa-expert|wixqa-simulated> --model=<provider:model|deterministic> [options]
      --dimensions=N             embedding dimensions (sent to the provider)
      --mode=dense               retrieval mode (dense only until B6)
      --concurrency=1,4,8        client processes per throughput run
      --warmup=20                untimed queries before the timed runs
      --queries=N --docs=N       fixed smaller run (default: the whole dataset)
      --truncate=N               cut documents to N characters (0: none)
      --out=/tmp/ag2/bench       report folder (never inside a repo)
      --compare[=file]           fail on a quality drop against the baseline
                                 (default benchmarks/baselines/<dataset>/<model-slug>.json)
      --tolerance=0.01           allowed absolute quality drop for --compare
      --price-per-1m-tokens=X    embedding price, for the cost section
    Or: bin/vector-bench --report=<report.json> --compare=<baseline.json> [--tolerance=0.01]
      compares an existing report without a run.
    Env: OPENAI_API_KEY (or <PROVIDER>_API_KEY), VECTOR_PG_HOST, VECTOR_PG_PORT, VECTOR_PG_DATABASE,
    VECTOR_PG_USERNAME, VECTOR_PG_PASSWORD (defaults: the test database on 127.0.0.1:5433).

    TXT;

    private string $container = '';

    /** @var list<float> */
    private array $memory = [];

    private float $lastSample = 0.0;

    /** @var list<VectorEmbeddingCompleted> */
    private array $embedCalls = [];

    /**
     * @param  array<string, string|false|list<string|false>>  $options  getopt() with self::OPTIONS
     */
    public static function main(array $options): int
    {
        if (isset($options['child'])) {
            self::child((string) $options['child'], (string) $options['slice'], (string) $options['results']);

            return 0;
        }

        if (isset($options['report'])) {
            return self::compareReport(self::readJson((string) $options['report']), $options);
        }

        if (isset($options['help']) || ! isset($options['dataset'], $options['model'])) {
            fwrite(STDERR, self::USAGE);

            return isset($options['help']) ? 0 : 2;
        }

        return (new self)->run($options);
    }

    /**
     * @param  array<string, mixed>  $embedding  vector.embeddings.providers.* entry, or [] for none
     */
    public static function boot(string $driver, array $embedding): void
    {
        $app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
        $app['config']->set('vector', require self::ROOT.'/config/vector.php');
        $app['config']->set('database.default', 'pgvector');
        $app['config']->set('database.connections.pgvector', PgvectorTestHelper::connectionConfig());
        $app['config']->set('vector.stores.pgvector.connection', 'pgvector');
        $app['config']->set('vector.embeddings.default', $driver);
        $app['config']->set("vector.embeddings.providers.{$driver}", $embedding);
        $app->register(PrismServiceProvider::class);
        $app->register(VectorServiceProvider::class);
    }

    /**
     * Child process: a slice of pre-embedded queries after the parent's go signal, one JSON line per query.
     */
    public static function child(string $jobFile, string $slice, string $resultsFile): void
    {
        /** @var array{collection: string, top_k: int, queries: list<array{id: string, vector: list<float>}>} $job */
        $job = self::readJson($jobFile);
        [$index, $of] = array_map('intval', explode('/', $slice));
        self::boot('deterministic', []);
        $ids = static fn (array $vector): array => Vector::store('pgvector')->collection($job['collection'])
            ->usingVector($vector)->take($job['top_k'])->get()->pluck('id');
        $search = static fn (array $vector): array => array_map('strval', $ids($vector));
        $mine = array_values(array_filter($job['queries'], static fn (int $i): bool => $i % $of === $index, ARRAY_FILTER_USE_KEY));
        $search($job['queries'][0]['vector']);

        fwrite(STDOUT, "ready\n");
        fgets(STDIN);

        $out = fopen($resultsFile, 'w') ?: throw new RuntimeException("Cannot write {$resultsFile}.");

        foreach ($mine as $query) {
            $started = hrtime(true);
            $ids = $search($query['vector']);
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            fwrite($out, json_encode(['id' => $query['id'], 'ms' => round($elapsed, 3), 'at' => microtime(true), 'ids' => $ids], JSON_THROW_ON_ERROR)."\n");
        }

        fclose($out);
    }

    /**
     * @param  array<string, string|false|list<string|false>>  $options
     */
    public function run(array $options): int
    {
        $runStarted = hrtime(true);
        $model = (string) $options['model'];
        $dimensions = isset($options['dimensions']) ? (int) $options['dimensions'] : null;
        $mode = (string) ($options['mode'] ?? 'dense');
        $levels = array_map('intval', explode(',', (string) ($options['concurrency'] ?? '1,4,8')));
        $warmup = (int) ($options['warmup'] ?? 20);
        $truncate = (int) ($options['truncate'] ?? 0);
        $price = isset($options['price-per-1m-tokens']) ? (float) $options['price-per-1m-tokens'] : null;
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($model)), '-');
        $out = rtrim((string) ($options['out'] ?? '/tmp/ag2/bench'), '/');

        if (! in_array($mode, self::MODES, true)) {
            throw new RuntimeException("Unknown --mode={$mode}. Available: ".implode(', ', self::MODES).'.');
        }

        if (in_array(0, $levels, true)) {
            throw new RuntimeException('--concurrency takes positive numbers, for example 1,4,8.');
        }

        if (str_starts_with(($out[0] === '/' ? $out : getcwd().'/'.$out).'/', (string) realpath(self::ROOT).'/')) {
            throw new RuntimeException("--out={$out} is inside the package repo. Use a folder outside version control, for example /tmp/ag2/bench.");
        }

        @mkdir($out.'/cache', 0777, true);

        // Preflight: env keys, pgvector, dataset checksums, embedding endpoint.
        if ($model === 'deterministic') {
            $driver = 'deterministic';
            $embedding = ['dimensions' => $dimensions ?? 8];
        } else {
            $driver = 'prism';
            $embedding = ['driver' => 'prism', 'model' => $model, 'dimensions' => $dimensions];
            $vendor = explode(':', $model, 2)[0];
            $keyEnv = strtoupper($vendor).'_API_KEY';

            if ($vendor !== 'ollama' && (getenv($keyEnv) === false || getenv($keyEnv) === '')) {
                throw new RuntimeException("Missing env key {$keyEnv} for --model={$model}.");
            }
        }

        if (! PgvectorTestHelper::isAvailable()) {
            throw new RuntimeException('pgvector is not reachable. Check VECTOR_PG_HOST, VECTOR_PG_PORT, VECTOR_PG_DATABASE, VECTOR_PG_USERNAME, VECTOR_PG_PASSWORD (and VECTOR_PGVECTOR_INTEGRATION).');
        }

        $dataset = BenchDataset::load((string) $options['dataset']);
        $full = [count($dataset['corpus']), count($dataset['queries'])];
        $dataset = BenchDataset::subset(
            $dataset,
            isset($options['docs']) ? (int) $options['docs'] : null,
            isset($options['queries']) ? (int) $options['queries'] : null,
        );

        if ($dataset['queries'] === []) {
            throw new RuntimeException('No queries left in this run size; raise --docs or --queries.');
        }

        self::boot($driver, $embedding);

        try {
            $provider = Embeddings::using();
            $provider->embed('vector-bench preflight');
            $dimensions = $provider->dimensions();
        } catch (Throwable $exception) {
            throw new RuntimeException('Embedding endpoint preflight failed: '.$exception->getMessage(), previous: $exception);
        }

        $this->container = trim((string) shell_exec('docker ps --filter publish='.escapeshellarg((string) PgvectorTestHelper::connectionConfig()['port']).' --format "{{.Names}}" 2>/dev/null'));

        Event::listen(VectorEmbeddingCompleted::class, function (VectorEmbeddingCompleted $event): void {
            $this->embedCalls[] = $event;
        });
        $upsertEmbeddingMs = 0;
        Event::listen(VectorOperationCompleted::class, static function (VectorOperationCompleted $event) use (&$upsertEmbeddingMs): void {
            $upsertEmbeddingMs += $event->operation === 'upsert' ? (int) $event->embeddingMs : 0;
        });

        // Ingest once.
        $collection = 'bench_'.substr(hash('sha256', $dataset['name'].$slug.getmypid().microtime()), 0, 12);
        $store = Vector::store('pgvector');
        $db = DB::connection('pgvector');
        $this->sample(true);
        $store->createCollection(new CollectionDefinition(name: $collection, dimensions: $dimensions));

        try {
            self::say("Ingesting {$dataset['name']}: ".count($dataset['corpus'])." documents into {$collection}");
            $this->embedCalls = [];
            $started = hrtime(true);
            $store->collection($collection)
                ->onProgress(function (int $done, int $total): void {
                    $this->sample();

                    if ($done === $total || $done % 1000 === 0) {
                        self::say("Ingested {$done}/{$total}");
                    }
                })
                ->upsert(array_map(
                    static fn (array $row): Document => Document::make($row['id'], BenchDataset::truncate($row['text'], $truncate)),
                    $dataset['corpus'],
                ));
            $ingestMs = self::ms($started);
            $ingestCalls = $this->embedCalls;
            $this->sample(true);

            $storage = (string) $db->table((string) config('vector.stores.pgvector.registry_table', 'vector_collection_registry'))
                ->where('name', $collection)->value('storage_identifier');
            $started = hrtime(true);
            $db->statement("REINDEX INDEX {$storage}_embedding_idx");
            $rebuildMs = self::ms($started);
            /** @var object{t: int|string, i: int|string, a: int|string} $sizes */
            $sizes = $db->selectOne('SELECT pg_table_size(?::regclass) AS t, pg_indexes_size(?::regclass) AS i, pg_total_relation_size(?::regclass) AS a', [$storage, $storage, $storage]);
            $indexDef = (string) $db->scalar('SELECT pg_get_indexdef(?::regclass)', ["{$storage}_embedding_idx"]);
            $this->sample(true);

            // Query embeddings, one call per query, cached per dataset, model and dimensions.
            $cacheFile = "{$out}/cache/{$dataset['name']}.{$slug}.{$dimensions}.json";
            /** @var array<string, array{vector: list<float>, ms: int, tokens: ?int}> $cache */
            $cache = is_file($cacheFile) ? self::readJson($cacheFile) : [];
            $cached = true;

            foreach ($dataset['queries'] as $query) {
                if (isset($cache[$query['id']])) {
                    continue;
                }

                $cached = false;
                $this->embedCalls = [];
                $started = hrtime(true);
                $vector = $provider->embed($query['text']);
                $cache[$query['id']] = ['vector' => $vector, 'ms' => self::ms($started), 'tokens' => self::tokens($this->embedCalls)];
            }

            file_put_contents($cacheFile, json_encode($cache, JSON_THROW_ON_ERROR));
            $queries = array_map(static fn (array $query): array => ['id' => $query['id'], 'vector' => $cache[$query['id']]['vector']], $dataset['queries']);
            $queryEmbedding = array_map(static fn (array $query): array => $cache[$query['id']], $dataset['queries']);

            // Warmup in the parent, then the timed runs in separate processes.
            self::say("Warmup: {$warmup} queries");
            for ($i = 0; $i < $warmup; $i++) {
                $store->collection($collection)->usingVector($queries[$i % count($queries)]['vector'])->take(self::TOP_K)->get();
            }

            $jobFile = "{$out}/cache/job-{$collection}.json";
            file_put_contents($jobFile, json_encode(['collection' => $collection, 'top_k' => self::TOP_K, 'queries' => $queries], JSON_THROW_ON_ERROR));
            $runs = [];
            $ranked = [];

            foreach ($levels as $level) {
                self::say("Concurrency {$level}: ".count($queries).' queries');
                $lines = $this->runLevel($jobFile, "{$out}/cache/results-{$collection}-{$level}", $level);

                if (count($lines) !== count($queries)) {
                    throw new RuntimeException("Concurrency {$level}: ".count($lines).' of '.count($queries).' queries returned.');
                }

                $latencies = array_column($lines, 'ms');
                $first = min(array_map(static fn (array $line): float => $line['at'] - $line['ms'] / 1000, $lines));
                $wall = max(array_column($lines, 'at')) - $first;
                $runs[$level] = [
                    'queries' => count($lines),
                    'wall_ms' => (int) round($wall * 1000),
                    'qps' => round(count($lines) / max($wall, 1e-9), 1),
                    'p50_ms' => BenchReport::percentile($latencies, 50),
                    'p95_ms' => BenchReport::percentile($latencies, 95),
                    'p99_ms' => BenchReport::percentile($latencies, 99),
                ];
                $ranked = $ranked ?: array_column($lines, 'ids', 'id');
            }

            unlink($jobFile);
        } finally {
            $store->deleteCollection($collection);
        }

        $ingestTokens = self::tokens($ingestCalls);
        $queryTokens = self::sumTokens(array_column($queryEmbedding, 'tokens'));
        $embeddingMs = array_column($queryEmbedding, 'ms');
        /** @var array<string, mixed> $meta */
        $meta = $dataset['meta'];

        $report = [
            'schema' => 1,
            'created_at' => date(DATE_ATOM),
            'dataset' => [
                'name' => $dataset['name'],
                'description' => $meta['description'],
                'license' => $meta['license'],
                'citation' => $meta['citation'],
                'revision' => data_get($meta, 'source.revision') ?? data_get($meta, 'source.sha256'),
                'files' => array_map(static fn (array $file): string => $file['sha256'], (array) $meta['files']),
            ],
            'model' => ['name' => $model, 'slug' => $slug, 'dimensions' => $dimensions],
            'truncate' => $truncate,
            'sizes' => [
                'documents' => count($dataset['corpus']),
                'queries' => count($dataset['queries']),
                'top_k' => self::TOP_K,
                'warmup' => $warmup,
                'subset' => [count($dataset['corpus']), count($dataset['queries'])] !== $full,
            ],
            'store' => ['name' => 'pgvector', 'index' => $indexDef],
            'ingest' => [
                'documents' => count($dataset['corpus']),
                'wall_ms' => $ingestMs,
                'docs_per_second' => round(count($dataset['corpus']) / max($ingestMs / 1000, 1e-9), 1),
                'embedding_ms' => $upsertEmbeddingMs,
                'embedding_calls' => count($ingestCalls),
                'embedding_tokens' => $ingestTokens,
                'index_rebuild_ms' => $rebuildMs,
            ],
            'query_embedding' => [
                'calls' => count($queryEmbedding),
                'tokens' => $queryTokens,
                'cached' => $cached,
                'p50_ms' => BenchReport::percentile($embeddingMs, 50),
                'p95_ms' => BenchReport::percentile($embeddingMs, 95),
                'p99_ms' => BenchReport::percentile($embeddingMs, 99),
            ],
            'modes' => [$mode => ['quality' => BenchReport::quality($dataset['qrels'], $ranked), 'concurrency' => $runs]],
            'resources' => [
                'table_bytes' => (int) $sizes->t,
                'index_bytes' => (int) $sizes->i,
                'total_bytes' => (int) $sizes->a,
                'container' => $this->container ?: null,
                'memory_mib_max' => $this->memory === [] ? null : round(max($this->memory), 1),
                'memory_samples' => count($this->memory),
                'memory_note' => $this->container === '' ? 'no docker container publishes the pgvector port' : null,
            ],
            'cost' => [
                'price_per_1m_tokens' => $price,
                'ingest' => $price === null || $ingestTokens === null ? null : round($ingestTokens * $price / 1_000_000, 6),
                'queries' => $price === null || $queryTokens === null ? null : round($queryTokens * $price / 1_000_000, 6),
            ],
            'infrastructure' => [
                'php' => PHP_VERSION,
                'postgres' => (string) $db->scalar('SELECT version()'),
                'pgvector' => (string) $db->scalar("SELECT extversion FROM pg_extension WHERE extname = 'vector'"),
                'os' => php_uname('s').' '.php_uname('r'),
            ],
            'runtime_ms' => self::ms($runStarted),
        ];

        $base = "{$out}/{$dataset['name']}.{$slug}.".date('Ymd-His');
        file_put_contents("{$base}.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        file_put_contents("{$base}.md", BenchReport::markdown($report));
        fwrite(STDOUT, BenchReport::markdown($report)."\nReport: {$base}.json\n");

        return array_key_exists('compare', $options) ? self::compareReport($report, $options) : 0;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, string|false|list<string|false>>  $options
     */
    public static function compareReport(array $report, array $options): int
    {
        $tolerance = (float) ($options['tolerance'] ?? 0.01);
        $baselineFile = is_string($options['compare'] ?? null)
            ? $options['compare']
            : self::ROOT.'/benchmarks/baselines/'.data_get($report, 'dataset.name').'/'.data_get($report, 'model.slug').'.json';

        if (! is_file($baselineFile)) {
            throw new RuntimeException("No baseline at {$baselineFile}.");
        }

        $result = BenchReport::compare($report, self::readJson($baselineFile), $tolerance);

        foreach ([...$result['notes'], ...$result['failures']] as $line) {
            fwrite(STDOUT, "{$line}\n");
        }

        if ($result['failures'] !== []) {
            return 1;
        }

        fwrite(STDOUT, "No quality drop beyond {$tolerance} against {$baselineFile}.\n");

        return 0;
    }

    /**
     * Start $level child processes behind a ready/go barrier and merge their JSON lines.
     *
     * @return list<array{id: string, ms: float, at: float, ids: list<string>}>
     */
    private function runLevel(string $jobFile, string $resultsPrefix, int $level): array
    {
        $children = [];

        for ($i = 0; $i < $level; $i++) {
            $results = "{$resultsPrefix}-{$i}.jsonl";
            $process = proc_open(
                [PHP_BINARY, self::BIN, "--child={$jobFile}", "--slice={$i}/{$level}", "--results={$results}"],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
                $pipes,
            ) ?: throw new RuntimeException('Cannot start a query process.');
            $children[] = ['process' => $process, 'pipes' => $pipes, 'results' => $results];
        }

        foreach ($children as $child) {
            if (trim((string) fgets($child['pipes'][1])) !== 'ready') {
                throw new RuntimeException('A query process did not start; see its output above.');
            }
        }

        foreach ($children as $child) {
            fwrite($child['pipes'][0], "go\n");
            fflush($child['pipes'][0]);
        }

        foreach ($children as $child) {
            while (proc_get_status($child['process'])['running']) {
                $this->sample();
                usleep(100_000);
            }

            if (proc_close($child['process']) !== 0 && ! is_file($child['results'])) {
                throw new RuntimeException('A query process failed; see its output above.');
            }
        }

        $lines = [];

        foreach ($children as $child) {
            foreach (file($child['results'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                /** @var array{id: string, ms: float, at: float, ids: list<string>} $decoded */
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $lines[] = $decoded;
            }

            unlink($child['results']);
        }

        return $lines;
    }

    /**
     * Sample the pgvector container's memory, at most every 2 seconds unless forced.
     */
    private function sample(bool $force = false): void
    {
        if ($this->container === '' || (! $force && microtime(true) - $this->lastSample < 2.0)) {
            return;
        }

        $this->lastSample = microtime(true);
        $usage = (string) shell_exec('docker stats --no-stream --format "{{.MemUsage}}" '.escapeshellarg($this->container).' 2>/dev/null');

        if (preg_match('/^([\d.]+)\s*([KMG]i?B)/', trim($usage), $match)) {
            $this->memory[] = (float) $match[1] * ['KiB' => 1 / 1024, 'KB' => 1 / 1024, 'MiB' => 1, 'MB' => 1, 'GiB' => 1024, 'GB' => 1024][$match[2]];
        }
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

    /**
     * @param  list<VectorEmbeddingCompleted>  $calls
     */
    private static function tokens(array $calls): ?int
    {
        return self::sumTokens(array_map(static fn (VectorEmbeddingCompleted $event): ?int => $event->tokens, $calls));
    }

    /**
     * @param  list<?int>  $tokens
     */
    private static function sumTokens(array $tokens): ?int
    {
        return array_filter($tokens, static fn (?int $t): bool => $t !== null) === [] ? null : (int) array_sum($tokens);
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
