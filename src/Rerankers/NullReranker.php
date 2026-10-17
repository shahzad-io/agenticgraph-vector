<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Rerankers;

use AgenticGraph\Vector\Contracts\RerankerContract;
use AgenticGraph\Vector\Support\RerankOrder;
use AgenticGraph\Vector\ValueObjects\RerankResult;

/**
 * Keeps the input order (every rerank score 0). For tests and for turning reranking off by config.
 */
final class NullReranker implements RerankerContract
{
    public function rerank(string $query, array $hits, int $topN): RerankResult
    {
        return new RerankResult(RerankOrder::apply($hits, array_map(static fn (int $index): array => ['index' => $index, 'score' => 0.0], array_keys($hits)), $topN), 'null');
    }
}
