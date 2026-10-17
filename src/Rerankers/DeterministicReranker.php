<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Rerankers;

use AgenticGraph\Vector\Contracts\RerankerContract;
use AgenticGraph\Vector\Stores\Memory\MemoryKeywordMatcher;
use AgenticGraph\Vector\Support\RerankOrder;
use AgenticGraph\Vector\ValueObjects\RerankResult;
use AgenticGraph\Vector\ValueObjects\SearchHit;

/**
 * Scores each hit by how many distinct query words its content holds (the memory store's `any` keyword
 * match), ties in input order. Predictable, no network: for tests.
 */
final class DeterministicReranker implements RerankerContract
{
    public function rerank(string $query, array $hits, int $topN): RerankResult
    {
        $matcher = new MemoryKeywordMatcher($query, 'any');
        $ranked = array_map(
            static fn (int $index, SearchHit $hit): array => ['index' => $index, 'score' => $matcher->score($hit->content) ?? 0.0],
            array_keys($hits),
            $hits,
        );

        return new RerankResult(RerankOrder::apply($hits, $ranked, $topN), 'deterministic');
    }
}
