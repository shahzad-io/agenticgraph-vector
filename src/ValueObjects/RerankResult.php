<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

/**
 * A reranker's answer: hits in the new order (at most topN), with `scores['rerank']` set. $units are billed
 * search units (Cohere), $tokens billed or used tokens (Voyage, Prism); null when the provider reports none.
 * $failedOpen: the reranker failed and these are the first topN input hits, unchanged (`vector.rerank.fail_open`).
 */
final readonly class RerankResult
{
    /**
     * @param  list<SearchHit>  $hits
     */
    public function __construct(
        public array $hits,
        public string $model,
        public ?int $units = null,
        public ?int $tokens = null,
        public bool $failedOpen = false,
    ) {}
}
