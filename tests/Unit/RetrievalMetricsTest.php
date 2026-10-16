<?php

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
