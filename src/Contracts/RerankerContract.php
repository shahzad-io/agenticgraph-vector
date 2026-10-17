<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

use AgenticGraph\Vector\ValueObjects\RerankResult;
use AgenticGraph\Vector\ValueObjects\SearchHit;

interface RerankerContract
{
    /**
     * Order $hits by relevance to $query and keep at most $topN. Hits come back unchanged except
     * `scores['rerank']`; `score` stays the vector similarity. Empty $hits make no request.
     *
     * @param  list<SearchHit>  $hits
     */
    public function rerank(string $query, array $hits, int $topN): RerankResult;
}
