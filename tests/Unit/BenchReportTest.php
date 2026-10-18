<?php

use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\BenchDataset;
use AgenticGraph\Vector\Tests\Support\BenchReport;
use AgenticGraph\Vector\Tests\Support\BenchRunner;
use AgenticGraph\Vector\Tests\Support\RetrievalMetrics;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

function benchReport(array $quality = [], array $overrides = []): array
{
    $report = [
        'created_at' => '2026-10-16T00:00:00+00:00',
        'dataset' => ['name' => 'scifact', 'description' => 'SciFact', 'license' => 'CC BY-SA 4.0', 'revision' => 'r1'],
        'model' => ['name' => 'openai:text-embedding-3-small', 'dimensions' => 1536],
        'truncate' => 0,
        'sizes' => ['documents' => 100, 'queries' => 10, 'top_k' => 10, 'warmup' => 5, 'subset' => false],
        'store' => ['name' => 'pgvector', 'index' => 'CREATE INDEX ...'],
        'ingest' => ['documents' => 100, 'wall_ms' => 1000, 'docs_per_second' => 100.0, 'embedding_ms' => 800, 'embedding_calls' => 1, 'embedding_tokens' => 5000, 'index_rebuild_ms' => 20],
        'query_embedding' => ['calls' => 10, 'tokens' => 90, 'cached' => false, 'p50_ms' => 100, 'p95_ms' => 150, 'p99_ms' => 160],
        'modes' => ['dense' => [
            'quality' => array_merge(array_fill_keys(BenchReport::QUALITY_KEYS, 0.5), $quality),
            'concurrency' => [1 => ['queries' => 10, 'wall_ms' => 20, 'qps' => 500.0, 'p50_ms' => 1.5, 'p95_ms' => 2.0, 'p99_ms' => 2.5]],
        ]],
        'resources' => ['table_bytes' => 1, 'index_bytes' => 2, 'total_bytes' => 3, 'container' => null, 'memory_mib_max' => null, 'memory_samples' => 0, 'memory_note' => 'no container'],
        'cost' => ['price_per_1m_tokens' => 0.02, 'ingest' => 0.0001, 'queries' => 0.0000018],
        'infrastructure' => ['php' => '8.4', 'postgres' => 'PostgreSQL 16', 'pgvector' => '0.8.0'],
    ];

    return array_replace_recursive($report, $overrides);
}

it('passes when quality stays within the tolerance of the baseline', function () {
    $result = BenchReport::compare(benchReport(['ndcg_at_10' => 0.495]), benchReport(), 0.01);

    expect($result['failures'])->toBe([])
        ->and($result['notes'])->toContain('dense store p50 at concurrency 1: 1.5 ms -> 1.5 ms (informational).');
});

it('fails on a quality drop beyond the tolerance, naming mode and metric', function () {
    $result = BenchReport::compare(benchReport(['recall_at_5' => 0.48]), benchReport(), 0.01);

    expect($result['failures'])->toBe(['dense recall_at_5 dropped from 0.5000 to 0.4800 (-0.0200, tolerance 0.0100).']);
});

it('treats latency and cost changes as notes, never failures', function () {
    $slower = benchReport(overrides: ['ingest' => ['embedding_tokens' => 9000], 'modes' => ['dense' => ['concurrency' => [1 => ['p50_ms' => 50.0]]]]]);

    $result = BenchReport::compare($slower, benchReport(), 0.01);

    expect($result['failures'])->toBe([])
        ->and($result['notes'])->toContain('Ingest embedding tokens: 5000 -> 9000 (informational).');
});

it('refuses to compare runs with different settings, truncation included', function () {
    $result = BenchReport::compare(benchReport(overrides: ['truncate' => 512]), benchReport(), 0.01);

    expect($result['failures'])->toBe(['Not comparable: truncate is 512, the baseline has 0.']);
});

it('computes nearest-rank percentiles', function () {
    expect(BenchReport::percentile([5, 1, 4, 2, 3], 50))->toBe(3)
        ->and(BenchReport::percentile(range(1, 100), 95))->toBe(95)
        ->and(BenchReport::percentile(range(1, 100), 99))->toBe(99)
        ->and(BenchReport::percentile([], 50))->toBeNull();
});

it('renders the markdown summary with every quality metric and the throughput rows', function () {
    $markdown = BenchReport::markdown(benchReport());

    foreach (BenchReport::QUALITY_KEYS as $metric) {
        expect($markdown)->toContain("| {$metric} | 0.5000 |");
    }

    expect($markdown)->toContain('| dense | 1 | 500.0 | 1.5 | 2 | 2.5 |')
        ->and($markdown)->toContain('Index rebuild (REINDEX): 20 ms');
});

it('refuses a dataset file whose sha256 differs from the manifest', function () {
    $dir = sys_get_temp_dir().'/vector-bench-'.uniqid();
    mkdir($dir.'/d', 0777, true);
    file_put_contents($dir.'/d/corpus.json', '[]');
    file_put_contents($dir.'/manifest.json', json_encode(['datasets' => ['d' => ['files' => [
        'corpus' => ['path' => 'd/corpus.json', 'sha256' => hash('sha256', '[{"id":"1","text":"x"}]')],
    ]]]]));

    expect(fn () => BenchDataset::load('d', $dir.'/manifest.json', $dir))->toThrow(RuntimeException::class, 'the manifest pins');

    array_map('unlink', [$dir.'/d/corpus.json', $dir.'/manifest.json']);
    rmdir($dir.'/d');
    rmdir($dir);
});

it('builds a fixed-size subset that keeps the relevant documents of the kept queries', function () {
    $dataset = [
        'corpus' => array_map(static fn (int $i): array => ['id' => "d{$i}", 'text' => "text {$i}"], range(1, 10)),
        'queries' => [['id' => 'q1', 'text' => 'one'], ['id' => 'q2', 'text' => 'two'], ['id' => 'q3', 'text' => 'three']],
        'qrels' => ['q1' => ['d9'], 'q2' => ['d7', 'd8', 'd10'], 'q3' => ['d2']],
    ];

    $subset = BenchDataset::subset($dataset, documents: 3);

    expect(array_column($subset['corpus'], 'id'))->toBe(['d1', 'd2', 'd9'])
        ->and(array_column($subset['queries'], 'id'))->toBe(['q1', 'q3'])
        ->and($subset['qrels'])->toBe(['q1' => ['d9'], 'q3' => ['d2']])
        ->and(BenchDataset::subset($dataset, queries: 1)['qrels'])->toBe(['q1' => ['d9']]);
});

it('renders hybrid as a delta against the first mode, with the queries below take', function () {
    $hybrid = ['quality' => array_fill_keys(BenchReport::QUALITY_KEYS, 0.55), 'below_take' => 0, 'concurrency' => [1 => ['queries' => 10, 'wall_ms' => 30, 'qps' => 333.3, 'p50_ms' => 3.0, 'p95_ms' => 4.0, 'p99_ms' => 5.0]]];
    $markdown = BenchReport::markdown(benchReport(overrides: ['modes' => ['dense' => ['below_take' => 2], 'hybrid' => $hybrid]]));

    expect($markdown)->toContain('| Metric | dense | hybrid (delta vs dense) |')
        ->and($markdown)->toContain('| ndcg_at_10 | 0.5000 | 0.5500 (+0.0500) |')
        ->and($markdown)->toContain('Queries with fewer than 10 hits: dense 2, hybrid 0.')
        ->and($markdown)->toContain('| hybrid | 1 | 333.3 | 3 | 4 | 5 |');

    $filtered = benchReport(overrides: ['filter' => ['field' => 'product', 'queries' => 8, 'unfiltered_queries' => 10]]);
    $filtered['modes'] = ['dense@off' => $filtered['modes']['dense'], 'dense@relaxed_order' => $hybrid];
    $markdown = BenchReport::markdown($filtered);

    expect($markdown)->toContain('- Filtered set: field `product`, 8 of 10 queries')
        ->and($markdown)->toContain('| Metric | dense@off | dense@relaxed_order (delta vs dense@off) |');
});

it('refuses to compare a filtered run with an unfiltered baseline', function () {
    $result = BenchReport::compare(benchReport(overrides: ['filter' => ['field' => 'bucket', 'queries' => 9, 'unfiltered_queries' => 10]]), benchReport(), 0.01);

    expect($result['failures'])->toHaveCount(1)
        ->and($result['failures'][0])->toContain('filter.field');
});

it('derives the filtered set: WixQA product, SciFact bucket, queries whose relevant documents share one value', function () {
    expect(BenchRunner::filterField('wixqa-expert'))->toBe('product')
        ->and(BenchRunner::filterField('scifact'))->toBe('bucket')
        ->and(BenchRunner::filterValue('product', ['id' => 'x', 'text' => "Wix Stores: Adding Products\nBody: with a colon"]))->toBe('Wix Stores')
        ->and(BenchRunner::filterValue('product', ['id' => 'x', 'text' => "No product here\nBody: colon"]))->toBeNull()
        ->and(BenchRunner::filterValue('bucket', ['id' => '4983', 'text' => 't']))->toBe(crc32('4983') % 20);

    $values = ['a' => 'Wix Stores', 'b' => 'Wix Stores', 'c' => 'Wix Blog', 'd' => null];

    expect(BenchRunner::queryFilters(['q1' => ['a', 'b'], 'q2' => ['a', 'c'], 'q3' => ['d'], 'q4' => ['c'], 'q5' => ['missing']], $values))
        ->toBe(['q1' => 'Wix Stores', 'q4' => 'Wix Blog']);
});

it('gives benchmark documents lang en and runs hybrid queries in any match mode', function () {
    config()->set('vector.stores.pgvector', ['driver' => 'memory']);
    config()->set('vector.hybrid.match', 'websearch');
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition('bench_unit', dimensions: 2, keyword: true));
    $store->collection('bench_unit')->upsert([
        Document::make('near', 'unrelated text', BenchRunner::documentMetadata(null, null), [1.0, 0.0]),
        Document::make('far', 'reset a password', BenchRunner::documentMetadata('bucket', 3), [0.0, 1.0]),
    ]);
    $job = ['collection' => 'bench_unit', 'top_k' => 2, 'mode' => 'hybrid', 'field' => null, 'options' => []];

    // A question no document holds every word of: websearch (the config here) would find no keyword hit.
    expect(BenchRunner::documentMetadata(null, null))->toBe(['lang' => 'en'])
        ->and(BenchRunner::documentMetadata('bucket', 3))->toBe(['lang' => 'en', 'bucket' => 3])
        ->and(BenchRunner::documentMetadata('product', null))->toBe(['lang' => 'en'])
        ->and(BenchRunner::searchIds($job, ['text' => 'How do I reset my password?', 'vector' => [1.0, 0.0], 'filter' => null]))->toBe(['far', 'near'])
        ->and(BenchRunner::searchIds(['mode' => 'dense'] + $job, ['text' => 'How do I reset my password?', 'vector' => [1.0, 0.0], 'filter' => null]))->toBe(['near', 'far']);
});

it('computes per-query metrics and a seeded paired bootstrap interval', function () {
    $qrels = ['q1' => ['a'], 'q2' => ['b', 'c']];
    $ranked = ['q1' => ['x', 'a'], 'q2' => ['b']];

    expect(RetrievalMetrics::recallPerQuery($qrels, $ranked, 1))->toBe(['q1' => 0.0, 'q2' => 0.5])
        ->and(RetrievalMetrics::ndcgPerQuery($qrels, $ranked, 10)['q1'])->toEqualWithDelta(1 / log(3, 2), 1e-12);

    $same = BenchReport::pairedBootstrap(['q1' => 0.5, 'q2' => 0.2], ['q1' => 0.5, 'q2' => 0.2]);
    $better = array_combine(array_map(fn (int $i): string => "q{$i}", range(1, 50)), array_map(fn (int $i): float => $i % 5 === 0 ? 0.0 : 0.1, range(1, 50)));
    $zero = array_fill_keys(array_keys($better), 0.0);
    $interval = BenchReport::pairedBootstrap($better, $zero, 2000);

    expect($same)->toMatchArray(['mean' => 0.0, 'low' => 0.0, 'high' => 0.0, 'queries' => 2])
        ->and($interval['mean'])->toEqualWithDelta(0.08, 1e-12)
        ->and($interval['low'])->toBeGreaterThan(0.0)->toBeLessThan(0.08)
        ->and($interval['high'])->toBeGreaterThan(0.08)->toBeLessThanOrEqual(0.1)
        ->and(BenchReport::pairedBootstrap($better, $zero, 2000))->toBe($interval)
        ->and(fn () => BenchReport::pairedBootstrap(['q1' => 1.0], ['q2' => 1.0]))->toThrow(InvalidArgumentException::class);
});

it('expands hybrid into sweep keys and reads the keyword candidate list in keyword order', function () {
    config()->set('vector.hybrid.match', 'websearch');
    config()->set('vector.hybrid.keyword_weight', 0.25);

    expect(array_keys(BenchRunner::variants(['dense', 'hybrid'], ['any', 'websearch'], ['0', '0.5'], true)))->toBe(['dense', 'hybrid:any:0', 'hybrid:any:0.5', 'hybrid:websearch:0', 'hybrid:websearch:0.5'])
        ->and(BenchRunner::variants(['dense', 'hybrid'], [null], [null], false))->toBe([
            'dense' => ['mode' => 'dense', 'match' => null, 'keyword_weight' => null],
            'hybrid' => ['mode' => 'hybrid', 'match' => 'websearch', 'keyword_weight' => 0.25],
        ]);

    config()->set('vector.stores.pgvector', ['driver' => 'memory']);
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition('bench_kw', dimensions: 2, keyword: true));
    $store->collection('bench_kw')->upsert([
        Document::make('near', 'unrelated', [], [1.0, 0.0]),
        Document::make('one', 'reset', [], [0.0, 1.0]),
        Document::make('two', 'reset password', [], [0.1, 1.0]),
    ]);
    $job = ['collection' => 'bench_kw', 'top_k' => 3, 'mode' => 'dense', 'field' => null, 'options' => [], 'match' => 'any'];
    $query = ['text' => 'reset my password', 'vector' => [1.0, 0.0], 'filter' => null];

    // Keyword order: two (both words) before one; the dense-only document is not in the keyword list.
    expect(BenchRunner::keywordIds($job, $query))->toBe(['two', 'one'])
        ->and(BenchRunner::searchIds(['mode' => 'hybrid', 'keyword_weight' => 0.0] + $job, $query))->toBe(BenchRunner::searchIds($job, $query));
});

it('reports same-budget candidate recall, paired intervals and the raw union separately', function () {
    $hybrid = benchReport()['modes']['dense'];
    $hybrid['settings'] = ['match' => 'any', 'keyword_weight' => 0.25];
    $hybrid['candidates'] = ['recall_at_30' => 0.9, 'recall_at_50' => 0.95, 'recall_at_100' => 0.97];
    $hybrid['paired'] = ['reference' => 'dense', 'ndcg_at_10' => ['mean' => -0.01, 'low' => -0.02, 'high' => 0.005], 'candidate_recall_at_30' => ['mean' => 0.02, 'low' => 0.01, 'high' => 0.03]];
    $report = benchReport();
    $report['modes']['dense']['candidates'] = ['recall_at_30' => 0.88, 'recall_at_50' => 0.93, 'recall_at_100' => 0.96];
    $report['modes']['hybrid:any:0.25'] = $hybrid;
    $report['union'] = ['any' => ['k_30' => ['recall' => 0.95, 'documents_mean' => 51.5, 'documents_max' => 60]]];
    $markdown = BenchReport::markdown($report);

    expect($markdown)->toContain('Hybrid settings: hybrid:any:0.25 match any, keyword weight 0.25.')
        ->and($markdown)->toContain('| hybrid:any:0.25 | 0.9000 (+0.0200) | 0.9500 (+0.0200) | 0.9700 (+0.0100) |')
        ->and($markdown)->toContain('| hybrid:any:0.25 | dense | -0.0100 [-0.0200, +0.0050] | n/a | +0.0200 [+0.0100, +0.0300] |')
        ->and($markdown)->toContain('not a same-budget comparison')
        ->and($markdown)->toContain('| any | 30 | 0.9500 | 51.5, 60 |');
});

it('keys reranked twins and pairs them against dense+rerank', function () {
    expect(BenchRunner::rerankKey('dense', null))->toBe('dense+rerank')
        ->and(BenchRunner::rerankKey('hybrid:any:0.25', 'off'))->toBe('hybrid:any:0.25+rerank@off')
        ->and(BenchRunner::pairedReference('hybrid:any:0.25+rerank'))->toBe('dense+rerank')
        ->and(BenchRunner::pairedReference('hybrid:any:0.25+rerank@off'))->toBe('dense+rerank@off')
        ->and(BenchRunner::pairedReference('hybrid:any:0.25'))->toBe('dense')
        ->and(BenchRunner::pairedReference('dense+rerank'))->toBe('dense')
        ->and(BenchRunner::pairedReference('dense+rerank@off'))->toBe('dense@off')
        ->and(BenchRunner::pairedReference('hybrid@relaxed_order'))->toBe('dense@relaxed_order')
        ->and(BenchRunner::RERANK_CANDIDATES)->toBe(BenchRunner::CANDIDATE_KS[0]);
});

it('reranks each query to the top 10 once and caches per query and ordered candidate ids', function () {
    $cacheFile = sys_get_temp_dir().'/ag2-rerank-cache-'.getmypid().'.json';
    @unlink($cacheFile);
    $hits = array_map(fn (int $i): SearchHit => new SearchHit("d{$i}", 1 - $i / 100, $i % 4 === 0 ? 'password reset' : "other {$i}"), range(0, 11));
    $queries = [['id' => 'q1', 'text' => 'password reset'], ['id' => 'q2', 'text' => 'nothing matches']];
    $byQuery = ['q1' => $hits, 'q2' => $hits];

    try {
        $first = BenchRunner::rerankRanked('deterministic', $byQuery, $queries, $cacheFile);
        $again = BenchRunner::rerankRanked('deterministic', $byQuery, $queries, $cacheFile);
        $otherOrder = BenchRunner::rerankRanked('deterministic', ['q1' => array_reverse($hits), 'q2' => $hits], $queries, $cacheFile);
    } finally {
        @unlink($cacheFile);
    }

    expect($first['ranked']['q1'])->toBe(['d0', 'd4', 'd8', 'd1', 'd2', 'd3', 'd5', 'd6', 'd7', 'd9'])
        ->and($first['ranked']['q2'])->toBe(array_map(fn (int $i): string => "d{$i}", range(0, 9)))
        ->and([$first['stats']['calls'], $first['stats']['cached']])->toBe([2, 0])
        ->and($again['ranked'])->toBe($first['ranked'])
        ->and([$again['stats']['calls'], $again['stats']['cached'], $again['stats']['run_units']])->toBe([0, 2, null])
        ->and([$otherOrder['stats']['calls'], $otherOrder['stats']['cached']])->toBe([1, 1])
        ->and($first['stats']['p50_ms'])->toBeInt();
});

it('prices rerank units and tokens from the flags', function () {
    expect(BenchRunner::rerankCost(3, null, 2.5, null))->toBe(0.0075)
        ->and(BenchRunner::rerankCost(null, 2_000_000, null, 0.05))->toBe(0.1)
        ->and(BenchRunner::rerankCost(3, null, null, 0.05))->toBeNull()
        ->and(BenchRunner::rerankCost(null, null, 2.5, 0.05))->toBeNull();
});

it('renders the rerank table, the reference column and the rerank cost', function () {
    $twin = benchReport()['modes']['dense'];
    $twin['concurrency'] = [];
    $twin['paired'] = ['reference' => 'dense', 'ndcg_at_10' => ['mean' => 0.05, 'low' => 0.02, 'high' => 0.08], 'recall_at_5' => ['mean' => 0.07, 'low' => 0.04, 'high' => 0.1], 'candidate_recall_at_30' => ['mean' => 0.0, 'low' => 0.0, 'high' => 0.0]];
    $twin['rerank'] = ['reranker' => 'cohere', 'model' => 'rerank-v4.0-pro', 'candidates' => 30, 'calls' => 290, 'cached' => 10, 'p50_ms' => 180, 'p95_ms' => 320, 'units' => 300, 'tokens' => null, 'run_units' => 290, 'run_tokens' => null];
    $report = benchReport();
    $report['modes']['dense+rerank'] = $twin;
    $report['cost']['rerank'] = ['reranker' => 'cohere', 'model' => 'rerank-v4.0-pro', 'price_per_1k_searches' => 2.5, 'price_per_1m_tokens' => null, 'calls' => 290, 'units' => 290, 'tokens' => null, 'run' => 0.725, 'per_query' => 0.0025];

    $markdown = BenchReport::markdown($report);

    expect($markdown)->toContain('| dense+rerank | dense | +0.0500 [+0.0200, +0.0800] | +0.0700 [+0.0400, +0.1000] |')
        ->and($markdown)->toContain('| dense+rerank | cohere (`rerank-v4.0-pro`) | 30 | 290 (10) | 180 | 320 | 300 | n/a |')
        ->and($markdown)->toContain('- Rerank (cohere, `rerank-v4.0-pro`): 290 requests this run (290 units, n/a tokens), cost 0.725; per query 0.0025');
});

it('compares a chunked run only with a chunked baseline', function () {
    $chunk = ['size' => 2000, 'overlap' => 200, 'chunks' => 300, 'collapse' => 'parent_id', 'overfetch' => 3];
    $chunked = benchReport(overrides: ['chunk' => $chunk]);

    expect(BenchReport::compare($chunked, benchReport(), 0.01)['failures'])->toBe(['Not comparable: chunk is '.json_encode($chunk).', the baseline has null.'])
        ->and(BenchReport::compare($chunked, $chunked, 0.01)['failures'])->toBe([])
        ->and(BenchReport::markdown($chunked))->toContain('- Chunks: 2000 characters, overlap 200, 300 chunks; hits collapsed by `parent_id`');
});

it('scores chunk hits as their parents and collapses reranked chunks after the rerank', function () {
    config()->set('vector.stores.pgvector', ['driver' => 'memory']);
    $store = Vector::store('pgvector');
    $store->createCollection(new CollectionDefinition('bench_chunks', dimensions: 2));
    $store->collection('bench_chunks')->upsert([
        Document::make('a#0', 'a0', ['parent_id' => 'a'], [1.0, 0.0]),
        Document::make('a#1', 'a1', ['parent_id' => 'a'], [0.9, 0.1]),
        Document::make('b#0', 'b0', ['parent_id' => 'b'], [0.5, 0.5]),
        Document::make('plain', 'plain', [], [0.0, 1.0]),
    ]);
    $job = ['collection' => 'bench_chunks', 'top_k' => 3, 'mode' => 'dense', 'field' => null, 'options' => []];
    $query = ['text' => 'q', 'vector' => [1.0, 0.0], 'filter' => null];
    $hits = BenchRunner::searchHits($job, $query);

    expect(BenchRunner::searchIds($job, $query))->toBe(['a', 'a', 'b'])
        ->and(BenchRunner::searchIds(['collapse' => true] + $job, $query))->toBe(['a', 'b', 'plain'])
        ->and(BenchRunner::collapseRanked(['q' => ['b#0', 'a#1', 'a#0']], ['q' => $hits]))->toBe(['q' => ['b', 'a']]);
});

it('takes a rerank candidate count only at a candidate recall depth', function () {
    (new BenchRunner)->run(['dataset' => 'wixqa-expert', 'model' => 'deterministic', 'rerank' => 'deterministic', 'rerank-candidates' => '40']);
})->throws(RuntimeException::class, '--rerank-candidates takes 30, 50, 100.');

it('caches document vectors by the sha256 of their text and reuses them only for the same text', function () {
    $documents = [Document::make('a', 'alpha text'), Document::make('b', 'beta text'), Document::make('c', 'gamma text', vector: [9.0, 9.0])];

    $cache = BenchRunner::documentVectors($documents, ['a' => '[0.5,0.25]', 'c' => '[1,1]']);
    $again = BenchRunner::withCachedVectors([Document::make('a2', 'alpha text', ['lang' => 'en']), Document::make('b', 'beta text')], $cache);

    expect($cache)->toBe([hash('sha256', 'alpha text') => [0.5, 0.25]])
        ->and(array_map(fn (Document $document): ?array => $document->vector, $again))->toBe([[0.5, 0.25], null])
        ->and([$again[0]->id, $again[0]->metadata])->toBe(['a2', ['lang' => 'en']]);
});

it('records input and output rerank tokens and prices them apart, old cache entries all as input', function () {
    Prism::fake(array_fill(0, 2, StructuredResponseFake::make()->withStructured(['rankings' => [['index' => 0, 'score' => 9]]])->withUsage(new Usage(1_000, 200))));
    config()->set('vector.rerank.providers.llm', ['driver' => 'prism', 'provider' => 'openai', 'model' => 'test-model']);
    $cacheFile = sys_get_temp_dir().'/ag2-rerank-split-'.getmypid().'.json';
    $hits = [new SearchHit('d0', 0.9, 'zero'), new SearchHit('d1', 0.8, 'one')];
    $queries = [['id' => 'q1', 'text' => 'one'], ['id' => 'q2', 'text' => 'two'], ['id' => 'old', 'text' => 'cached before the split']];
    file_put_contents($cacheFile, json_encode([sha1("old\0d0\0d1") => ['ids' => ['d1', 'd0'], 'ms' => 5, 'units' => null, 'tokens' => 600]]));

    try {
        $stats = BenchRunner::rerankRanked('llm', ['q1' => $hits, 'q2' => $hits, 'old' => $hits], $queries, $cacheFile)['stats'];
    } finally {
        @unlink($cacheFile);
    }

    $cost = BenchRunner::rerankCostSection('llm', 'openai:test-model', ['dense' => ['rerank' => $stats]], null, null, 1.0, 4.0);

    expect([$stats['input_tokens'], $stats['output_tokens'], $stats['unsplit'], $stats['run_input_tokens'], $stats['run_output_tokens'], $stats['run_unsplit']])->toBe([2_600, 400, 1, 2_000, 400, 0])
        ->and([$cost['input_tokens'], $cost['output_tokens'], $cost['unsplit']])->toBe([2_000, 400, 1])
        // This run: 2,000 input at 1.0 and 400 output at 4.0 per 1M; per query adds the old entry's 600 tokens as input.
        ->and($cost['run'])->toBe(0.0036)
        ->and($cost['per_query'])->toBe(round((0.0036 + 0.0006) / 3, 6))
        ->and(BenchRunner::rerankCost(null, 1_000_000, null, 1.0, 1_000_000, 4.0))->toBe(5.0)
        ->and(BenchReport::markdown(benchReport(overrides: ['cost' => ['rerank' => $cost]])))->toContain('- Rerank token prices split: 1 per 1M input tokens (2000 this run), 4 per 1M output tokens (400 this run); 1 cached requests without the split priced all as input');
});
