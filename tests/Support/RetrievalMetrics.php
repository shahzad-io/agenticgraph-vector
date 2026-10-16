<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use Closure;

/**
 * Standard IR metrics for labeled retrieval benchmarks (BEIR qrels). Every metric is the mean
 * over all queries in the qrels; a query with no ranked results scores 0.
 */
final class RetrievalMetrics
{
    /**
     * BEIR recall@k: relevant documents found in the top k divided by all relevant documents.
     *
     * @param  array<string, list<string>>  $qrels  query id => relevant document ids
     * @param  array<string, list<string>>  $ranked  query id => ranked document ids (best first)
     */
    public static function recallAtK(array $qrels, array $ranked, int $k): float
    {
        return self::average($qrels, $ranked, static fn (array $relevant, array $rankedIds): float => $relevant === []
            ? 0.0
            : count(array_intersect($relevant, array_slice($rankedIds, 0, $k))) / count($relevant));
    }

    /**
     * Hit rate at k: 1 when any relevant document is in the top k (the pre-B4 "recall").
     *
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     */
    public static function hitAtK(array $qrels, array $ranked, int $k): float
    {
        return self::average($qrels, $ranked, static fn (array $relevant, array $rankedIds): float => array_intersect($relevant, array_slice($rankedIds, 0, $k)) === [] ? 0.0 : 1.0);
    }

    /**
     * MRR@k: reciprocal rank of the first relevant document within the top k.
     *
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     */
    public static function mrrAtK(array $qrels, array $ranked, int $k): float
    {
        return self::average($qrels, $ranked, static fn (array $relevant, array $rankedIds): float => self::reciprocalRank(array_slice($rankedIds, 0, $k), $relevant));
    }

    /**
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     */
    public static function ndcgAtK(array $qrels, array $ranked, int $k): float
    {
        return self::average($qrels, $ranked, static fn (array $relevant, array $rankedIds): float => self::ndcgForQuery(array_slice($rankedIds, 0, $k), $relevant, $k));
    }

    /**
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     * @param  Closure(list<string>, list<string>): float  $score
     */
    private static function average(array $qrels, array $ranked, Closure $score): float
    {
        if ($qrels === []) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($qrels as $queryId => $relevant) {
            $total += $score($relevant, $ranked[$queryId] ?? []);
        }

        return $total / count($qrels);
    }

    /**
     * @param  list<string>  $rankedIds
     * @param  list<string>  $relevantIds
     */
    private static function reciprocalRank(array $rankedIds, array $relevantIds): float
    {
        foreach ($rankedIds as $index => $docId) {
            if (in_array($docId, $relevantIds, true)) {
                return 1.0 / ($index + 1);
            }
        }

        return 0.0;
    }

    /**
     * @param  list<string>  $rankedIds
     * @param  list<string>  $relevantIds
     */
    private static function ndcgForQuery(array $rankedIds, array $relevantIds, int $k): float
    {
        $dcg = 0.0;

        foreach ($rankedIds as $index => $docId) {
            if (! in_array($docId, $relevantIds, true)) {
                continue;
            }

            $dcg += 1.0 / log($index + 2, 2);
        }

        $idealCount = min(count($relevantIds), $k);
        $idcg = 0.0;

        for ($i = 0; $i < $idealCount; $i++) {
            $idcg += 1.0 / log($i + 2, 2);
        }

        if ($idcg === 0.0) {
            return 0.0;
        }

        return $dcg / $idcg;
    }
}
