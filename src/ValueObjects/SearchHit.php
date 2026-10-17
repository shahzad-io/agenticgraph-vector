<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

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
