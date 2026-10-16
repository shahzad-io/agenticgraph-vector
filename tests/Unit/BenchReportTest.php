<?php

use AgenticGraph\Vector\Tests\Support\BenchDataset;
use AgenticGraph\Vector\Tests\Support\BenchReport;

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
