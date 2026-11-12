<?php

use AgenticGraph\Vector\Tests\Support\BenchDataset;
use AgenticGraph\Vector\Tests\Support\RetrievalMetrics;

// q1 has two relevant documents (found at ranks 1 and 3), q2 one (rank 2), q3 one that is never ranked.
$qrels = ['q1' => ['a', 'b'], 'q2' => ['c'], 'q3' => ['d']];
$ranked = ['q1' => ['a', 'x', 'b', 'y'], 'q2' => ['y', 'c', 'z']];

it('computes BEIR recall@k as the share of all relevant documents found in the top k', function () use ($qrels, $ranked) {
    expect(RetrievalMetrics::recallAtK($qrels, $ranked, 1))->toBe((0.5 + 0 + 0) / 3)
        ->and(RetrievalMetrics::recallAtK($qrels, $ranked, 2))->toBe((0.5 + 1 + 0) / 3)
        ->and(RetrievalMetrics::recallAtK($qrels, $ranked, 3))->toBe((1 + 1 + 0) / 3);
});

it('computes hit@k as the share of queries with any relevant document in the top k', function () use ($qrels, $ranked) {
    expect(RetrievalMetrics::hitAtK($qrels, $ranked, 1))->toBe(1 / 3)
        ->and(RetrievalMetrics::hitAtK($qrels, $ranked, 2))->toBe(2 / 3)
        ->and(RetrievalMetrics::hitAtK($qrels, $ranked, 10))->toBe(2 / 3);
});

it('cuts MRR at k', function () use ($qrels, $ranked) {
    expect(RetrievalMetrics::mrrAtK($qrels, $ranked, 10))->toBe((1 + 0.5 + 0) / 3)
        ->and(RetrievalMetrics::mrrAtK($qrels, $ranked, 1))->toBe(1 / 3);
});

it('guard: nDCG@k uses binary gains and the ideal ranking of all relevant documents', function () use ($qrels, $ranked) {
    $q1 = (1 + 1 / log(4, 2)) / (1 + 1 / log(3, 2));
    $q2 = 1 / log(3, 2);

    expect(RetrievalMetrics::ndcgAtK($qrels, $ranked, 3))->toEqualWithDelta(($q1 + $q2 + 0) / 3, 1e-12)
        ->and(RetrievalMetrics::ndcgAtK([], $ranked, 10))->toBe(0.0);
});

it('uses relevance grades as linear gains in nDCG, and grade 1 for binary judgments (H5)', function () {
    $qrels = ['q' => ['a', 'b']];
    $ranked = ['q' => ['b', 'x', 'a']];

    // Binary: unchanged numbers. Graded: a is grade 2, b grade 1; ideal order a, b.
    expect(RetrievalMetrics::ndcgAtK($qrels, $ranked, 10))->toBe(RetrievalMetrics::ndcgAtK($qrels, $ranked, 10, ['q' => ['a' => 1, 'b' => 1]]))
        ->and(RetrievalMetrics::ndcgAtK($qrels, $ranked, 10, ['q' => ['a' => 2, 'b' => 1]]))->toEqualWithDelta((1 + 2 / 2) / (2 + 1 / log(3, 2)), 1e-12);
});

it('loads graded qrels as relevant ids plus grades, dropping grade 0 (H5)', function () {
    $dir = sys_get_temp_dir().'/ag2-graded-'.getmypid();
    @mkdir("{$dir}/g", 0777, true);
    $files = ['corpus' => [['id' => 'a', 'text' => 'A']], 'queries' => [['id' => 'q', 'text' => 'Q']], 'qrels' => ['q' => ['a' => 2, 'b' => 0], 'r' => ['c']]];
    $manifest = ['datasets' => ['g' => ['tier' => 'small', 'kind' => 'text', 'primary_metric' => 'ndcg_at_10', 'license' => 'MIT', 'license_note' => 'test', 'files' => []]]];

    foreach ($files as $part => $data) {
        file_put_contents("{$dir}/g/{$part}.json", $json = json_encode($data));
        $manifest['datasets']['g']['files'][$part] = ['path' => "g/{$part}.json", 'sha256' => hash('sha256', $json)];
    }

    file_put_contents("{$dir}/manifest.json", json_encode($manifest));
    $dataset = BenchDataset::load('g', "{$dir}/manifest.json", $dir);

    expect($dataset['qrels'])->toBe(['q' => ['a'], 'r' => ['c']])
        ->and($dataset['grades'])->toBe(['q' => ['a' => 2]]);

    array_map('unlink', [...glob("{$dir}/g/*") ?: [], "{$dir}/manifest.json"]);
    rmdir("{$dir}/g");
    rmdir($dir);
});
