<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

/**
 * Score contract (the collection's Metric):
 *
 * | Metric        | Score               | Range            |
 * |---------------|---------------------|------------------|
 * | cosine        | cosine similarity   | -1 to 1          |
 * | l2            | 1 / (1 + distance)  | above 0, up to 1 |
 * | inner_product | the dot product     | unbounded        |
 *
 * `scores['keyword']` is store-defined: compare it only between hits of one query.
 */
final readonly class SearchHit
{
    /**
     * `score` is the vector similarity in every mode. `scores` is set by hybrid search and by reranking, null
     * otherwise. Hybrid hits are ordered by `scores['fused']` and carry `keyword` (null for a hit that did not
     * match the keywords); reranked hits are ordered by `scores['rerank']` (null when the reranker left the hit
     * out). A search that failed open (`vector.rerank.fail_open`) returns hits without `rerank`.
     *
     * @param  array<string, mixed>  $metadata
     * @param  list<float>|null  $vector
     * @param  array{vector: float, keyword?: float|null, fused?: float, rerank?: float|null}|null  $scores
     */
    public function __construct(
        public string|int $id,
        public float $score,
        public string $content,
        public array $metadata = [],
        public ?array $vector = null,
        public ?array $scores = null,
    ) {}
}
