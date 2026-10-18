<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use AgenticGraph\Vector\Contracts\AliasStoreContract;
use AgenticGraph\Vector\Events\VectorEmbeddingCompleted;
use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Ingest\TextSplitter;
use AgenticGraph\Vector\Stores\PgVector\PgVectorSearchOptions;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
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

    public const MODES = ['dense', 'hybrid'];

    /** Same-budget candidate recall: dense top K against hybrid fused top K (30 is the B7 reranker's candidate count). */
    public const CANDIDATE_KS = [30, 50, 100];

    /** Every pipeline sends its own top 30 to the reranker (the candidate recall@30 budget); the reranker keeps TOP_K. */
    public const RERANK_CANDIDATES = 30;

    /**
     * A keyword weight that puts every keyword candidate above every dense-only one, so a hybrid search's fused
     * top K is the keyword list (needs weight * (1/(k+K) - 1/(k+K+1)) > 1/(k+1): above 420 for k 60, K 100).
     */
    private const KEYWORD_LIST_WEIGHT = 1e6;

    public const OPTIONS = [
        'dataset:', 'model:', 'dimensions:', 'mode:', 'concurrency:', 'warmup:', 'queries:', 'docs:',
        'truncate:', 'out:', 'compare::', 'tolerance:', 'price-per-1m-tokens:', 'report:', 'help',
        'collection:', 'child:', 'slice:', 'results:', 'filtered', 'iterative-scan:', 'match:', 'keyword-weight:', 'keep',
        'rerank:', 'rerank-price-per-1k-searches:', 'rerank-price-per-1m-tokens:', 'chunk:', 'rerank-candidates:',
        'rerank-price-per-1m-input-tokens:', 'rerank-price-per-1m-output-tokens:',
    ];

    public const USAGE = <<<'TXT'
    Usage: bin/vector-bench --dataset=<scifact|wixqa-expert|wixqa-simulated> --model=<provider:model|deterministic> [options]
      --dimensions=N             embedding dimensions (sent to the provider)
      --mode=dense,hybrid        retrieval modes, comma separated (hybrid: vector and keyword search, RRF)
      --filtered                 filtered query set: each query searches only the documents whose derived
                                 field (WixQA product, SciFact bucket) equals its relevant documents' value
      --iterative-scan=off,relaxed_order  run every mode once per HNSW iterative scan setting
                                 (report keys mode@setting; default: the store config, key mode)
      --match=any,websearch      hybrid keyword match modes to sweep (default: vector.hybrid.match)
      --keyword-weight=0,0.5,1   hybrid keyword weights to sweep (default: vector.hybrid.keyword_weight);
                                 with --match or --keyword-weight, hybrid keys are hybrid:<match>:<weight>
      --rerank=NAME              add a +rerank twin of every mode: its top 30 reranked by vector.rerank.providers.NAME
                                 to the top 10 (cached per reranker, model, query and candidate ids; one client)
      --rerank-candidates=30     candidates per query sent to the reranker: 30, 50 or 100 (a candidate recall depth)
      --rerank-price-per-1k-searches=X  reranker price per 1,000 searches (search units), for the cost section
      --rerank-price-per-1m-tokens=X    reranker price per 1M tokens, for the cost section
      --rerank-price-per-1m-input-tokens=X --rerank-price-per-1m-output-tokens=Y  split token prices (an LLM
                                 reranker); a cached request without the split is priced at X for all its tokens
      --keep                     keep the ingested collection (and its ingest numbers) for later --collection runs
      --concurrency=1,4,8        client processes per throughput run
      --warmup=20                untimed queries before the timed runs
      --queries=N --docs=N       fixed smaller run (default: the whole dataset)
      --truncate=N               cut documents to N characters (0: none)
                                 (document vectors are cached per dataset, model, dimensions and text sha256)
      --chunk=SIZE,OVERLAP       split documents at ingest (TextSplitter, characters) and collapse hits to their
                                 parent before scoring (rerank: the chunks are reranked, then collapsed)
      --out=~/ag2-scratch/bench  report folder (never inside a repo)
      --compare[=file]           fail on a quality drop against the baseline
                                 (default benchmarks/baselines/<dataset>/<model-slug>.json)
      --tolerance=0.01           allowed absolute quality drop for --compare
      --price-per-1m-tokens=X    embedding price, for the cost section
      --collection=NAME          search an existing collection or alias holding the corpus (no ingest, not dropped)
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
        // public, not the per-process test schema: the query children and later runs share the collections.
        $app['config']->set('database.connections.pgvector', ['search_path' => 'public'] + PgvectorTestHelper::connectionConfig());
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
        /** @var array{collection: string, top_k: int, mode: string, field: string|null, options: array<string, mixed>, queries: list<array{id: string, text: string, vector: list<float>, filter: string|int|null}>} $job */
        $job = self::readJson($jobFile);
        [$index, $of] = array_map('intval', explode('/', $slice));
        self::boot('deterministic', []);
        $mine = array_values(array_filter($job['queries'], static fn (int $i): bool => $i % $of === $index, ARRAY_FILTER_USE_KEY));
        self::searchIds($job, $job['queries'][0]);

        fwrite(STDOUT, "ready\n");
        fgets(STDIN);

        $out = fopen($resultsFile, 'w') ?: throw new RuntimeException("Cannot write {$resultsFile}.");

        foreach ($mine as $query) {
            $started = hrtime(true);
            $ids = self::searchIds($job, $query);
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            fwrite($out, json_encode(['id' => $query['id'], 'ms' => round($elapsed, 3), 'at' => microtime(true), 'ids' => $ids], JSON_THROW_ON_ERROR)."\n");
        }

        fclose($out);
    }

    /**
     * One query of a job: dense, or hybrid (the job's match mode and keyword weight; default any and 1) with the query
     * text as keywords; filtered by the derived field when set.
     *
     * @param  array{collection: string, top_k: int, mode: string, field: string|null, options: array<string, mixed>, match?: string, keyword_weight?: float}  $job
     * @param  array{text: string, vector: list<float>, filter: string|int|null}  $query
     * @return list<string>
     */
    public static function searchIds(array $job, array $query): array
    {
        return array_map(self::parentId(...), self::searchHits($job, $query));
    }

    /**
     * The document a hit scores as: its parent for a chunk (qrels name documents), else the hit itself.
     */
    public static function parentId(SearchHit $hit): string
    {
        return (string) ($hit->metadata['parent_id'] ?? $hit->id);
    }

    /**
     * The keyword candidate list of a hybrid job at its top_k: the hits that matched the keywords, in keyword order.
     *
     * @param  array{collection: string, top_k: int, mode: string, field: string|null, options: array<string, mixed>, match?: string, keyword_weight?: float}  $job
     * @param  array{text: string, vector: list<float>, filter: string|int|null}  $query
     * @return list<string>
     */
    public static function keywordIds(array $job, array $query): array
    {
        $hits = self::searchHits(['mode' => 'hybrid', 'keyword_weight' => self::KEYWORD_LIST_WEIGHT] + $job, $query);

        return array_values(array_map(self::parentId(...), array_filter($hits, static fn (SearchHit $hit): bool => ($hit->scores['keyword'] ?? null) !== null)));
    }

    /**
     * @param  array{collection: string, top_k: int, mode: string, field: string|null, options: array<string, mixed>, match?: string, keyword_weight?: float, collapse?: bool}  $job
     * @param  array{text: string, vector: list<float>, filter: string|int|null}  $query
     * @return list<SearchHit>
     */
    public static function searchHits(array $job, array $query): array
    {
        $builder = Vector::store('pgvector')->collection($job['collection'])->usingVector($query['vector'])->options($job['options'])->take($job['top_k']);
        $builder = $job['field'] !== null ? $builder->where($job['field'], $query['filter']) : $builder;
        $builder = ($job['collapse'] ?? false) ? $builder->collapse() : $builder;
        $builder = $job['mode'] === 'hybrid' ? $builder->keywords($query['text'])->hybrid(match: $job['match'] ?? 'any', keywordWeight: $job['keyword_weight'] ?? 1.0) : $builder;

        return $builder->get()->hits;
    }

    /**
     * The report key of a mode's reranked twin: dense+rerank, hybrid:any:0.25+rerank, dense+rerank@off.
     */
    public static function rerankKey(string $variant, ?string $scan): string
    {
        return $scan === null ? "{$variant}+rerank" : "{$variant}+rerank@{$scan}";
    }

    /**
     * The paired reference of a report key: dense+rerank for reranked twins, dense for dense+rerank itself and for
     * plain modes, at the same iterative scan setting.
     */
    public static function pairedReference(string $key): string
    {
        $scan = str_contains($key, '@') ? '@'.explode('@', $key, 2)[1] : '';
        $mode = $scan === '' ? $key : explode('@', $key, 2)[0];

        return ($mode !== 'dense+rerank' && str_contains($mode, '+rerank') ? 'dense+rerank' : 'dense').$scan;
    }

    /**
     * Rerank each query's candidates to TOP_K with the named reranker, one request at a time. Results are cached in
     * $cacheFile per query id and ordered candidate ids, with the request's time, units and tokens, so a rerun or a
     * mode with the same candidates (hybrid at weight 0) makes no request.
     *
     * @param  array<string, list<SearchHit>>  $hitsByQuery  query id => candidates, best first
     * @param  list<array{id: string, text: string}>  $queries
     * @return array{ranked: array<string, list<string>>, stats: array<string, int|float|null>}
     */
    public static function rerankRanked(string $reranker, array $hitsByQuery, array $queries, string $cacheFile, int $topN = self::TOP_K): array
    {
        /** @var array<string, array{ids: list<string>, ms: int, units: int|null, tokens: int|null, input_tokens?: int|null, output_tokens?: int|null}> $cache */
        $cache = is_file($cacheFile) ? self::readJson($cacheFile) : [];
        $ranked = [];
        $entries = [];
        $calls = 0;
        $runUnits = [];
        $runTokens = [];
        $runEntries = [];

        foreach ($queries as $query) {
            $hits = $hitsByQuery[$query['id']] ?? [];
            $cacheKey = sha1($query['id']."\0".implode("\0", array_map(static fn (SearchHit $hit): string => (string) $hit->id, $hits)).($topN === self::TOP_K ? '' : "\0top{$topN}"));

            if (! isset($cache[$cacheKey])) {
                $started = hrtime(true);
                $result = Vector::reranker($reranker)->rerank($query['text'], $hits, $topN);
                $cache[$cacheKey] = [
                    'ids' => array_map(static fn (SearchHit $hit): string => (string) $hit->id, $result->hits),
                    'ms' => self::ms($started),
                    'units' => $result->units,
                    'tokens' => $result->tokens,
                    'input_tokens' => $result->inputTokens,
                    'output_tokens' => $result->outputTokens,
                ];
                $calls++;
                $runUnits[] = $result->units;
                $runTokens[] = $result->tokens;
                $runEntries[] = $cache[$cacheKey];
                file_put_contents($cacheFile, json_encode($cache, JSON_THROW_ON_ERROR));
            }

            $ranked[$query['id']] = $cache[$cacheKey]['ids'];
            $entries[] = $cache[$cacheKey];
        }

        $latencies = array_column($entries, 'ms');

        return ['ranked' => $ranked, 'stats' => [
            'calls' => $calls,
            'cached' => count($entries) - $calls,
            'p50_ms' => BenchReport::percentile($latencies, 50),
            'p95_ms' => BenchReport::percentile($latencies, 95),
            'units' => self::sumTokens(array_column($entries, 'units')),
            'tokens' => self::sumTokens(array_column($entries, 'tokens')),
            'run_units' => self::sumTokens($runUnits),
            'run_tokens' => self::sumTokens($runTokens),
            ...self::splitTokens($entries),
            ...array_combine(['run_input_tokens', 'run_output_tokens', 'run_unsplit'], array_values(self::splitTokens($runEntries))),
        ]];
    }

    /**
     * Input and output tokens over cache entries. An entry without the split (cached before it was recorded, or a
     * reranker that reports one count) counts all its tokens as input; `unsplit` counts those entries.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{input_tokens: int|null, output_tokens: int|null, unsplit: int}
     */
    private static function splitTokens(array $entries): array
    {
        $split = array_filter($entries, static fn (array $entry): bool => isset($entry['input_tokens']));
        $unsplit = array_filter($entries, static fn (array $entry): bool => ! isset($entry['input_tokens']) && isset($entry['tokens']));

        return [
            'input_tokens' => self::sumTokens([...array_column($split, 'input_tokens'), ...array_column($unsplit, 'tokens')]),
            'output_tokens' => self::sumTokens(array_column($split, 'output_tokens')),
            'unsplit' => count($unsplit),
        ];
    }

    /**
     * The documents with the cached vector of their text (key: sha256 of the content); the others unchanged.
     *
     * @param  list<Document>  $documents
     * @param  array<string, list<float>>  $cache
     * @return list<Document>
     */
    public static function withCachedVectors(array $documents, array $cache): array
    {
        return array_map(static function (Document $document) use ($cache): Document {
            $vector = $cache[hash('sha256', $document->content)] ?? null;

            return $vector === null ? $document : new Document($document->id, $document->content, $document->metadata, $vector);
        }, $documents);
    }

    /**
     * Cache entries (sha256 of the content => vector) for the documents not cached yet, from the stored vectors.
     *
     * @param  list<Document>  $documents
     * @param  array<string|int, string>  $stored  id => pgvector text ("[0.1,0.2]")
     * @return array<string, list<float>>
     */
    public static function documentVectors(array $documents, array $stored): array
    {
        $entries = [];

        foreach ($documents as $document) {
            if ($document->vector === null && isset($stored[$document->id])) {
                $entries[hash('sha256', $document->content)] = array_map('floatval', json_decode($stored[$document->id], true, flags: JSON_THROW_ON_ERROR));
            }
        }

        return $entries;
    }

    /**
     * Reranked chunk ids collapsed to their parents (first chunk per parent), top TOP_K.
     *
     * @param  array<string, list<string>>  $ranked  query id => chunk ids
     * @param  array<string, list<SearchHit>>  $hitsByQuery  the reranked candidates, for their parents
     * @return array<string, list<string>>
     */
    public static function collapseRanked(array $ranked, array $hitsByQuery): array
    {
        foreach ($ranked as $queryId => $ids) {
            $parents = array_column(array_map(static fn (SearchHit $hit): array => ['id' => (string) $hit->id, 'parent' => self::parentId($hit)], $hitsByQuery[$queryId] ?? []), 'parent', 'id');
            $ranked[$queryId] = array_slice(array_values(array_unique(array_map(static fn (string $id): string => $parents[$id] ?? $id, $ids))), 0, self::TOP_K);
        }

        return $ranked;
    }

    /**
     * Money for $units search units, $tokens tokens and $outputTokens output tokens at the given prices (with split
     * prices, $tokens are the input tokens); null when no price applies.
     */
    public static function rerankCost(?int $units, ?int $tokens, ?float $perThousandSearches, ?float $perMillionTokens, ?int $outputTokens = null, ?float $perMillionOutputTokens = null): ?float
    {
        $parts = [
            $perThousandSearches !== null && $units !== null ? $units * $perThousandSearches / 1000 : null,
            $perMillionTokens !== null && $tokens !== null ? $tokens * $perMillionTokens / 1_000_000 : null,
            $perMillionOutputTokens !== null && $outputTokens !== null ? $outputTokens * $perMillionOutputTokens / 1_000_000 : null,
        ];

        return array_filter($parts, static fn (?float $part): bool => $part !== null) === [] ? null : round(array_sum($parts), 6);
    }

    /**
     * The rerank cost: this run's requests, and per query (every reranked mode's queries, cached ones included).
     *
     * @param  array<string, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public static function rerankCostSection(string $reranker, string $model, array $results, ?float $perThousandSearches, ?float $perMillionTokens, ?float $perMillionInputTokens = null, ?float $perMillionOutputTokens = null): array
    {
        $stats = array_values(array_filter(array_map(static fn (array $result): ?array => $result['rerank'] ?? null, $results)));
        $sum = static fn (string $field): ?int => self::sumTokens(array_column($stats, $field));
        $queries = (int) array_sum(array_map(static fn (array $s): int => $s['calls'] + $s['cached'], $stats));
        // Split prices: input tokens at one price, output at the other (stats from rerankRanked, with the fallback).
        $cost = static fn (string $prefix): ?float => $perMillionInputTokens !== null
            ? self::rerankCost($sum("{$prefix}units"), $sum("{$prefix}input_tokens"), $perThousandSearches, $perMillionInputTokens, $sum("{$prefix}output_tokens"), $perMillionOutputTokens)
            : self::rerankCost($sum("{$prefix}units"), $sum("{$prefix}tokens"), $perThousandSearches, $perMillionTokens);
        $all = $cost('');

        return [
            'reranker' => $reranker,
            'model' => $model,
            'price_per_1k_searches' => $perThousandSearches,
            'price_per_1m_tokens' => $perMillionTokens,
            ...($perMillionInputTokens === null ? [] : [
                'price_per_1m_input_tokens' => $perMillionInputTokens,
                'price_per_1m_output_tokens' => $perMillionOutputTokens,
                'input_tokens' => $sum('run_input_tokens'),
                'output_tokens' => $sum('run_output_tokens'),
                'unsplit' => (int) array_sum(array_column($stats, 'unsplit')),
            ]),
            'calls' => (int) array_sum(array_column($stats, 'calls')),
            'units' => $sum('run_units'),
            'tokens' => $sum('run_tokens'),
            'run' => $cost('run_'),
            'per_query' => $queries === 0 || $all === null ? null : round($all / $queries, 6),
        ];
    }

    /**
     * Metadata of a benchmark document: `lang: en` (the datasets are English, so keyword search stems and drops
     * stop words as it would for a real app's documents), plus the derived filter field when it has a value.
     *
     * @return array<string, string|int>
     */
    public static function documentMetadata(?string $field, string|int|null $value): array
    {
        return ['lang' => 'en', ...($field !== null && $value !== null ? [$field => $value] : [])];
    }

    /**
     * The modes of a run, keyed by report key: dense, and hybrid once per match mode and keyword weight (null takes
     * the package default). Sweep keys are hybrid:<match>:<weight>; a run without a sweep keeps the key hybrid.
     *
     * @param  list<string>  $modes
     * @param  list<string|null>  $matches
     * @param  list<string|null>  $weights
     * @return array<string, array{mode: string, match: string|null, keyword_weight: float|null}>
     */
    public static function variants(array $modes, array $matches, array $weights, bool $sweep): array
    {
        $variants = [];

        foreach ($modes as $mode) {
            if ($mode !== 'hybrid') {
                $variants[$mode] = ['mode' => $mode, 'match' => null, 'keyword_weight' => null];

                continue;
            }

            foreach ($matches as $match) {
                foreach ($weights as $weight) {
                    $match ??= (string) config('vector.hybrid.match', SearchQuery::KEYWORD_MATCH_ANY);
                    $weight = $weight === null ? (float) config('vector.hybrid.keyword_weight', 1.0) : (float) $weight;
                    $variants[$sweep ? "hybrid:{$match}:{$weight}" : 'hybrid'] = ['mode' => 'hybrid', 'match' => $match, 'keyword_weight' => $weight];
                }
            }
        }

        return $variants;
    }

    /**
     * The raw union of the dense top K and the keyword top K, per match mode: recall and document count. Not a
     * same-budget comparison (the union holds up to 2K documents).
     *
     * @param  array<string, array{mode: string, match: string|null, keyword_weight: float|null}>  $variants
     * @param  list<array{id: string, text: string, vector: list<float>, filter: string|int|null}>  $queries
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, array<int, array<string, list<string>>>>  $candidateRanked  key => K => query id => ids
     * @return array<string, array<string, array{recall: float, documents_mean: float, documents_max: int}>>|null
     */
    private function rawUnion(array $variants, ?string $scan, string $collection, ?string $field, array $queries, array $qrels, array $candidateRanked): ?array
    {
        $dense = $candidateRanked[$scan === null ? 'dense' : "dense@{$scan}"] ?? null;
        $matches = array_values(array_unique(array_filter(array_column($variants, 'match'))));

        if ($dense === null || $matches === []) {
            return null;
        }

        $union = [];

        foreach ($matches as $match) {
            self::say("raw union, match {$match}");

            foreach (self::CANDIDATE_KS as $k) {
                $ranked = [];
                $sizes = [];

                foreach ($queries as $query) {
                    $job = ['collection' => $collection, 'top_k' => $k, 'mode' => 'hybrid', 'field' => $field, 'match' => $match,
                        'options' => ($scan === null ? [] : [PgVectorSearchOptions::HNSW_ITERATIVE_SCAN => $scan]) + [PgVectorSearchOptions::HNSW_EF_SEARCH => max(40, $k)]];
                    $ranked[$query['id']] = array_values(array_unique([...$dense[$k][$query['id']], ...self::keywordIds($job, $query)]));
                    $sizes[] = count($ranked[$query['id']]);
                }

                $union[$match]["k_{$k}"] = [
                    'recall' => RetrievalMetrics::recallAtK($qrels, $ranked, 2 * $k),
                    'documents_mean' => round(array_sum($sizes) / max(count($sizes), 1), 1),
                    'documents_max' => $sizes === [] ? 0 : max($sizes),
                ];
            }
        }

        return $union;
    }

    /**
     * The derived field of a filtered run: WixQA articles by product, SciFact documents by a hash bucket.
     */
    public static function filterField(string $dataset): string
    {
        return str_starts_with($dataset, 'wixqa') ? 'product' : 'bucket';
    }

    /**
     * WixQA product: the title (first line) before its first `:`, null without one. SciFact bucket: crc32 of the id modulo 20.
     *
     * @param  array{id: string, text: string}  $document
     */
    public static function filterValue(string $field, array $document): string|int|null
    {
        if ($field === 'bucket') {
            return crc32($document['id']) % 20;
        }

        $title = strtok($document['text'], "\n") ?: '';

        return str_contains($title, ':') ? trim(strstr($title, ':', true)) : null;
    }

    /**
     * The filter value of each query whose relevant documents all share one non-null value; other queries are left out.
     *
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, string|int|null>  $values  document id => value
     * @return array<string, string|int>
     */
    public static function queryFilters(array $qrels, array $values): array
    {
        $filters = [];

        foreach ($qrels as $queryId => $relevant) {
            $shared = array_values(array_unique(array_map(static fn (string $id): string|int|null => $values[$id] ?? null, $relevant), SORT_REGULAR));

            if (count($shared) === 1 && $shared[0] !== null) {
                $filters[(string) $queryId] = $shared[0];
            }
        }

        return $filters;
    }

    /**
     * @param  array<string, string|false|list<string|false>>  $options
     */
    public function run(array $options): int
    {
        $runStarted = hrtime(true);
        $model = (string) $options['model'];
        $dimensions = isset($options['dimensions']) ? (int) $options['dimensions'] : null;
        $modes = array_values(array_unique(explode(',', (string) ($options['mode'] ?? 'dense'))));
        $iterative = isset($options['iterative-scan']) ? explode(',', (string) $options['iterative-scan']) : [null];
        $filtered = isset($options['filtered']);
        $sweep = isset($options['match']) || isset($options['keyword-weight']);
        $matches = isset($options['match']) ? array_values(array_unique(explode(',', (string) $options['match']))) : [null];
        $weights = isset($options['keyword-weight']) ? array_values(array_unique(explode(',', (string) $options['keyword-weight']))) : [null];
        $levels = array_map('intval', explode(',', (string) ($options['concurrency'] ?? '1,4,8')));
        $warmup = (int) ($options['warmup'] ?? 20);
        $truncate = (int) ($options['truncate'] ?? 0);
        $splitter = isset($options['chunk']) ? new TextSplitter(...array_map('intval', explode(',', (string) $options['chunk'], 2))) : null;
        $price = isset($options['price-per-1m-tokens']) ? (float) $options['price-per-1m-tokens'] : null;
        $rerank = isset($options['rerank']) ? (string) $options['rerank'] : null;
        $rerankCandidates = (int) ($options['rerank-candidates'] ?? self::RERANK_CANDIDATES);
        $rerankSearchPrice = isset($options['rerank-price-per-1k-searches']) ? (float) $options['rerank-price-per-1k-searches'] : null;
        $rerankTokenPrice = isset($options['rerank-price-per-1m-tokens']) ? (float) $options['rerank-price-per-1m-tokens'] : null;
        $rerankInputPrice = isset($options['rerank-price-per-1m-input-tokens']) ? (float) $options['rerank-price-per-1m-input-tokens'] : null;
        $rerankOutputPrice = isset($options['rerank-price-per-1m-output-tokens']) ? (float) $options['rerank-price-per-1m-output-tokens'] : null;

        if (($rerankInputPrice === null) !== ($rerankOutputPrice === null) || ($rerankInputPrice !== null && $rerankTokenPrice !== null)) {
            throw new RuntimeException('Pass both --rerank-price-per-1m-input-tokens and --rerank-price-per-1m-output-tokens, without --rerank-price-per-1m-tokens.');
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($model)), '-');
        $out = rtrim((string) ($options['out'] ?? self::defaultOut()), '/');

        if (array_diff($modes, self::MODES) !== []) {
            throw new RuntimeException('Unknown --mode='.implode(',', array_diff($modes, self::MODES)).'. Available: '.implode(', ', self::MODES).'.');
        }

        if (array_diff($iterative, [null, ...PgVectorSearchOptions::ITERATIVE_SCAN_MODES]) !== []) {
            throw new RuntimeException('--iterative-scan takes '.implode(', ', PgVectorSearchOptions::ITERATIVE_SCAN_MODES).'.');
        }

        if (array_diff($matches, [null, ...SearchQuery::KEYWORD_MATCH_MODES]) !== []) {
            throw new RuntimeException('--match takes '.implode(', ', SearchQuery::KEYWORD_MATCH_MODES).'.');
        }

        foreach ($weights as $weight) {
            if ($weight !== null && (! is_numeric($weight) || (float) $weight < 0.0)) {
                throw new RuntimeException('--keyword-weight takes numbers of at least 0, for example 0,0.25,1.');
            }
        }

        if ($sweep && ! in_array('hybrid', $modes, true)) {
            throw new RuntimeException('--match and --keyword-weight sweep the hybrid mode; add hybrid to --mode.');
        }

        if ($filtered && isset($options['collection'])) {
            throw new RuntimeException('--filtered derives document metadata at ingest, so it cannot reuse --collection.');
        }

        if (! in_array($rerankCandidates, self::CANDIDATE_KS, true)) {
            throw new RuntimeException('--rerank-candidates takes '.implode(', ', self::CANDIDATE_KS).'.');
        }

        if (in_array(0, $levels, true)) {
            throw new RuntimeException('--concurrency takes positive numbers, for example 1,4,8.');
        }

        if (str_starts_with(($out[0] === '/' ? $out : getcwd().'/'.$out).'/', (string) realpath(self::ROOT).'/')) {
            throw new RuntimeException("--out={$out} is inside the package repo. Use a folder outside version control, for example ~/ag2-scratch/bench.");
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

        $field = $filtered ? self::filterField($dataset['name']) : null;
        $values = [];
        $queryFilters = [];
        $unfiltered = null;

        if ($field !== null) {
            foreach ($dataset['corpus'] as $document) {
                $values[$document['id']] = self::filterValue($field, $document);
            }

            $unfiltered = count($dataset['queries']);
            $queryFilters = self::queryFilters($dataset['qrels'], $values);
            $dataset['queries'] = array_values(array_filter($dataset['queries'], static fn (array $query): bool => isset($queryFilters[$query['id']])));
            $dataset['qrels'] = array_intersect_key($dataset['qrels'], $queryFilters);
            self::say("Filtered set: {$field}, ".count($dataset['queries'])." of {$unfiltered} queries have relevant documents with one value");
        }

        if ($dataset['queries'] === []) {
            throw new RuntimeException('No queries left in this run size; raise --docs or --queries.');
        }

        self::boot($driver, $embedding);
        $overfetch = $splitter === null ? 1 : (int) config('vector.collapse.overfetch', 3);

        // A reranker failure aborts the run instead of failing open into the dense order.
        $rerankModel = null;

        if ($rerank !== null) {
            config(['vector.rerank.fail_open' => false]);

            try {
                Vector::reranker($rerank)->rerank('vector-bench preflight', [new SearchHit('preflight', 1.0, 'vector-bench preflight')], 1);
            } catch (Throwable $exception) {
                throw new RuntimeException("Reranker [{$rerank}] preflight failed: ".$exception->getMessage(), previous: $exception);
            }

            $rerankModel = (string) config("vector.rerank.providers.{$rerank}.model", $rerank);
        }

        // Embed a preflight probe only when this run embeds something: an ingest, or queries missing from the cache.
        $reused = isset($options['collection']) ? (string) $options['collection'] : null;

        try {
            $provider = Embeddings::using();
            $dimensions = $provider->dimensions();
            $cacheFile = "{$out}/cache/{$dataset['name']}.{$slug}.{$dimensions}.json";
            /** @var array<string, array{vector: list<float>, ms: int, tokens: ?int}> $cache */
            $cache = is_file($cacheFile) ? self::readJson($cacheFile) : [];

            if ($reused === null || array_diff(array_column($dataset['queries'], 'id'), array_keys($cache)) !== []) {
                $provider->embed('vector-bench preflight');
            }
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

        // Ingest once, or search an existing collection (for example one a migration built).
        $collection = $reused ?? 'bench_'.substr(hash('sha256', $dataset['name'].$slug.getmypid().microtime()), 0, 12);
        $store = Vector::store('pgvector');
        $db = DB::connection('pgvector');
        $this->sample(true);

        $hybrid = in_array('hybrid', $modes, true);
        $documents = array_map(
            static fn (array $row): Document => Document::make(
                $row['id'],
                BenchDataset::truncate($row['text'], $truncate),
                self::documentMetadata($field, $field !== null ? $values[$row['id']] : null),
            ),
            $dataset['corpus'],
        );
        $documents = $splitter === null ? $documents : array_merge(...array_map($splitter->chunk(...), $documents));
        // Document vectors, cached per dataset, model and dimensions by the sha256 of each text: a rerun ingests without embedding.
        $documentCacheFile = "{$out}/cache/{$dataset['name']}.{$slug}.{$dimensions}.documents.json";
        /** @var array<string, list<float>> $documentCache */
        $documentCache = $reused === null && is_file($documentCacheFile) ? self::readJson($documentCacheFile) : [];
        $documents = self::withCachedVectors($documents, $documentCache);
        $documentsCached = $reused === null && array_filter($documents, static fn (Document $document): bool => $document->vector === null) === [];

        if ($reused === null) {
            $store->createCollection(new CollectionDefinition(name: $collection, dimensions: $dimensions, keyword: $hybrid ?: null));
        } elseif (! $store instanceof AliasStoreContract || ($count = $store->countDocuments($reused)) !== count($documents)) {
            throw new RuntimeException("--collection={$reused} must hold exactly the corpus (".count($documents).($splitter === null ? ' documents' : ' chunks').'), it holds '.($count ?? 'an unknown number of').'.');
        } elseif ($hybrid && $store->describeCollection($reused)['keyword'] === null) {
            throw new RuntimeException("--collection={$reused} has no keyword search, so --mode=hybrid cannot run. Migrate it with --keyword first.");
        }

        try {
            self::say($reused === null ? "Ingesting {$dataset['name']}: ".count($dataset['corpus']).' documents'.($splitter === null ? '' : ' as '.count($documents).' chunks')." into {$collection}" : "Searching {$reused} (no ingest)");
            $this->embedCalls = [];
            $started = hrtime(true);
            $reused !== null || $store->collection($collection)
                ->onProgress(function (int $done, int $total): void {
                    $this->sample();

                    if ($done === $total || $done % 1000 === 0) {
                        self::say("Ingested {$done}/{$total}");
                    }
                })
                ->upsert($documents);
            $ingestMs = self::ms($started);
            $ingestCalls = $this->embedCalls;
            $this->sample(true);

            $storage = (string) $db->table((string) config('vector.stores.pgvector.registry_table', 'vector_collection_registry'))
                ->where('name', $store->describeCollection($collection)['collection'])->value('storage_identifier');

            if ($reused === null && ! $documentsCached) {
                $documentCache += self::documentVectors($documents, $db->table($storage)->pluck('embedding', 'id')->all());
                file_put_contents($documentCacheFile, json_encode($documentCache, JSON_THROW_ON_ERROR));
            }

            $started = hrtime(true);
            $reused !== null || $db->statement("REINDEX INDEX {$storage}_embedding_idx");
            $rebuildMs = self::ms($started);
            /** @var object{t: int|string, i: int|string, a: int|string} $sizes */
            $sizes = $db->selectOne('SELECT pg_table_size(?::regclass) AS t, pg_indexes_size(?::regclass) AS i, pg_total_relation_size(?::regclass) AS a', [$storage, $storage, $storage]);
            $indexDef = (string) $db->scalar('SELECT pg_get_indexdef(?::regclass)', ["{$storage}_embedding_idx"]);
            $this->sample(true);

            // Query embeddings, one call per query, cached per dataset, model and dimensions (loaded at preflight).
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
            $queries = array_map(static fn (array $query): array => [
                'id' => $query['id'],
                'text' => $query['text'],
                'vector' => $cache[$query['id']]['vector'],
                'filter' => $queryFilters[$query['id']] ?? null,
            ], $dataset['queries']);
            $queryEmbedding = array_map(static fn (array $query): array => $cache[$query['id']], $dataset['queries']);
            $results = [];
            $perQuery = [];
            $candidateRanked = [];
            $variants = self::variants($modes, $matches, $weights, $sweep);

            foreach ($variants as $variant => $settings) {
                foreach ($iterative as $scan) {
                    $key = $scan === null ? $variant : "{$variant}@{$scan}";
                    $job = [
                        'collection' => $collection,
                        'top_k' => self::TOP_K,
                        'mode' => $settings['mode'],
                        'field' => $field,
                        'options' => $scan === null ? [] : [PgVectorSearchOptions::HNSW_ITERATIVE_SCAN => $scan],
                        'collapse' => $splitter !== null,
                        'queries' => $queries,
                        ...($settings['mode'] === 'hybrid' ? ['match' => $settings['match'], 'keyword_weight' => $settings['keyword_weight']] : []),
                    ];

                    // Warmup in the parent, then the timed runs in separate processes.
                    self::say("{$key}: warmup {$warmup} queries");
                    for ($i = 0; $i < $warmup; $i++) {
                        self::searchIds($job, $queries[$i % count($queries)]);
                    }

                    $jobFile = "{$out}/cache/job-{$collection}.json";
                    file_put_contents($jobFile, json_encode($job, JSON_THROW_ON_ERROR));
                    $runs = [];
                    $ranked = [];

                    foreach ($levels as $level) {
                        self::say("{$key}, concurrency {$level}: ".count($queries).' queries');
                        $lines = $this->runLevel($jobFile, "{$out}/cache/results-{$collection}-{$level}", $level);

                        if (count($lines) !== count($queries)) {
                            throw new RuntimeException("{$key}, concurrency {$level}: ".count($lines).' of '.count($queries).' queries returned.');
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

                    // Same-budget candidate recall: the top K of this mode, with ef_search raised so HNSW can return K.
                    self::say("{$key}: candidate recall at ".implode(', ', self::CANDIDATE_KS));
                    $candidates = [];
                    $rerankHits = [];
                    $perQuery[$key] = ['ndcg_at_10' => RetrievalMetrics::ndcgPerQuery($dataset['qrels'], $ranked, self::TOP_K), 'recall_at_5' => RetrievalMetrics::recallPerQuery($dataset['qrels'], $ranked, 5)];

                    foreach (self::CANDIDATE_KS as $k) {
                        $kJob = ['top_k' => $k, 'options' => $job['options'] + [PgVectorSearchOptions::HNSW_EF_SEARCH => max(40, $k * $overfetch)]] + $job;
                        $kRanked = [];

                        foreach ($queries as $query) {
                            $hits = self::searchHits($kJob, $query);
                            $kRanked[$query['id']] = array_map(self::parentId(...), $hits);

                            // Chunked: the reranker gets the top chunks (Builder take(10)->rerank($candidates)->collapse()).
                            if ($rerank !== null && $k === $rerankCandidates) {
                                $rerankHits[$query['id']] = $splitter === null ? $hits : self::searchHits(['collapse' => false] + $kJob, $query);
                            }
                        }

                        $candidateRanked[$key][$k] = $kRanked;
                        $candidates["recall_at_{$k}"] = RetrievalMetrics::recallAtK($dataset['qrels'], $kRanked, $k);
                        $perQuery[$key]["candidate_recall_at_{$k}"] = RetrievalMetrics::recallPerQuery($dataset['qrels'], $kRanked, $k);
                    }

                    $results[$key] = [
                        ...($settings['mode'] === 'hybrid' ? ['settings' => ['match' => $settings['match'], 'keyword_weight' => $settings['keyword_weight']]] : []),
                        'quality' => BenchReport::quality($dataset['qrels'], $ranked),
                        'candidates' => $candidates,
                        'below_take' => count(array_filter($ranked, static fn (array $ids): bool => count($ids) < self::TOP_K)),
                        'concurrency' => $runs,
                    ];

                    if ($rerank !== null) {
                        $twin = self::rerankKey($variant, $scan);
                        self::say("{$twin}: reranking ".count($queries)." queries ({$rerankCandidates} candidates each, one client)");
                        $reranked = self::rerankRanked($rerank, $rerankHits, $queries, "{$out}/cache/rerank.{$dataset['name']}.{$rerank}.".trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $rerankModel)), '-').'.json', $splitter === null ? self::TOP_K : self::TOP_K * $overfetch);
                        $reranked['ranked'] = $splitter === null ? $reranked['ranked'] : self::collapseRanked($reranked['ranked'], $rerankHits);
                        $perQuery[$twin] = [
                            'ndcg_at_10' => RetrievalMetrics::ndcgPerQuery($dataset['qrels'], $reranked['ranked'], self::TOP_K),
                            'recall_at_5' => RetrievalMetrics::recallPerQuery($dataset['qrels'], $reranked['ranked'], 5),
                            'candidate_recall_at_30' => $perQuery[$key]['candidate_recall_at_30'],
                        ];
                        $results[$twin] = [
                            ...($settings['mode'] === 'hybrid' ? ['settings' => ['match' => $settings['match'], 'keyword_weight' => $settings['keyword_weight']]] : []),
                            'quality' => BenchReport::quality($dataset['qrels'], $reranked['ranked']),
                            'candidates' => $candidates,
                            'below_take' => count(array_filter($reranked['ranked'], static fn (array $ids): bool => count($ids) < self::TOP_K)),
                            'rerank' => ['reranker' => $rerank, 'model' => $rerankModel, 'candidates' => $rerankCandidates, ...($splitter === null ? [] : [
                                'collapse' => 'after rerank',
                                'candidate_documents_mean' => round(array_sum(array_map(static fn (array $hits): int => count(array_unique(array_map(self::parentId(...), $hits))), $rerankHits)) / max(count($rerankHits), 1), 1),
                            ])] + $reranked['stats'],
                            'concurrency' => [],
                        ];
                    }
                }
            }

            // Paired per-query differences against dense (dense+rerank for reranked twins) at the same iterative scan setting.
            foreach (array_keys($results) as $key) {
                $dense = self::pairedReference($key);

                if ($key !== $dense && isset($perQuery[$dense])) {
                    $results[$key]['paired'] = [
                        'reference' => $dense,
                        'ndcg_at_10' => BenchReport::pairedBootstrap($perQuery[$key]['ndcg_at_10'], $perQuery[$dense]['ndcg_at_10']),
                        'recall_at_5' => BenchReport::pairedBootstrap($perQuery[$key]['recall_at_5'], $perQuery[$dense]['recall_at_5']),
                        'candidate_recall_at_30' => BenchReport::pairedBootstrap($perQuery[$key]['candidate_recall_at_30'], $perQuery[$dense]['candidate_recall_at_30']),
                    ];
                }
            }

            // Per-query scores, so runs over the same queries can be paired later (for example two candidate counts).
            foreach (array_keys($results) as $key) {
                $results[$key]['per_query'] = ['ndcg_at_10' => $perQuery[$key]['ndcg_at_10'], 'recall_at_5' => $perQuery[$key]['recall_at_5']];
            }

            $union = $this->rawUnion($variants, $iterative[0], $collection, $field, $queries, $dataset['qrels'], $candidateRanked);
        } finally {
            if ($reused === null && isset($options['keep'])) {
                self::say("Kept collection {$collection} (search it again with --collection={$collection})");
            } elseif ($reused === null) {
                $store->deleteCollection($collection);
            }
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
            'chunk' => $splitter === null ? null : ['size' => $splitter->size, 'overlap' => $splitter->overlap, 'chunks' => count($documents), 'collapse' => 'parent_id', 'overfetch' => $overfetch],
            'sizes' => [
                'documents' => count($dataset['corpus']),
                'queries' => count($dataset['queries']),
                'top_k' => self::TOP_K,
                'warmup' => $warmup,
                'subset' => [count($dataset['corpus']), count($dataset['queries'])] !== $full,
            ],
            'store' => ['name' => 'pgvector', 'index' => $indexDef, 'collection' => $reused],
            'ingest' => [
                'documents' => count($dataset['corpus']),
                'wall_ms' => $ingestMs,
                'docs_per_second' => $reused !== null ? 0.0 : round(count($dataset['corpus']) / max($ingestMs / 1000, 1e-9), 1),
                'embedding_ms' => $upsertEmbeddingMs,
                'embedding_calls' => count($ingestCalls),
                'embedding_tokens' => $ingestTokens,
                'cached' => $documentsCached,
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
            'filter' => $field === null ? null : ['field' => $field, 'queries' => count($dataset['queries']), 'unfiltered_queries' => $unfiltered],
            'modes' => $results,
            'union' => $union,
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
                'rerank' => $rerank === null ? null : self::rerankCostSection($rerank, (string) $rerankModel, $results, $rerankSearchPrice, $rerankTokenPrice, $rerankInputPrice, $rerankOutputPrice),
            ],
            'infrastructure' => [
                'php' => PHP_VERSION,
                'postgres' => (string) $db->scalar('SELECT version()'),
                'pgvector' => (string) $db->scalar("SELECT extversion FROM pg_extension WHERE extname = 'vector'"),
                'os' => php_uname('s').' '.php_uname('r'),
            ],
            'runtime_ms' => self::ms($runStarted),
        ];

        // A kept collection records its ingest, so a later --collection run reports the run that built it.
        $ingestFile = "{$out}/cache/{$collection}.ingest.json";

        if ($reused === null && isset($options['keep'])) {
            file_put_contents($ingestFile, json_encode(['ingest' => $report['ingest'], 'cost_ingest' => $report['cost']['ingest']], JSON_THROW_ON_ERROR));
        } elseif ($reused !== null && is_file($ingestFile)) {
            /** @var array{ingest: array<string, mixed>, cost_ingest: float|null} $built */
            $built = self::readJson($ingestFile);
            $report['ingest'] = ['measured_by' => 'the run that built the collection'] + $built['ingest'];
            $report['cost']['ingest'] = $built['cost_ingest'];
        }

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
            : self::ROOT.'/benchmarks/baselines/'.data_get($report, 'dataset.name').'/'.data_get($report, 'model.slug').(data_get($report, 'chunk') === null ? '' : '.chunked').'.json';

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
     * Reports and caches are long-lived, so they live outside /tmp and every repo.
     */
    public static function defaultOut(): string
    {
        return rtrim((string) (getenv('HOME') ?: sys_get_temp_dir()), '/').'/ag2-scratch/bench';
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
