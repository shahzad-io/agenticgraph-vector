<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

/**
 * Standard IR metrics for labeled retrieval benchmarks (BEIR qrels).
 */
final class RetrievalMetrics
{
    /**
     * @param  array<string, list<string>>  $qrels  query id => relevant document ids
     * @param  array<string, list<string>>  $ranked  query id => ranked document ids (best first)
     */
    public static function recallAtK(array $qrels, array $ranked, int $k): float
    {
        if ($qrels === []) {
            return 0.0;
        }

        $hits = 0.0;

        foreach ($qrels as $queryId => $relevant) {
            if ($relevant === []) {
                continue;
            }

            $topK = array_slice($ranked[$queryId] ?? [], 0, $k);

            if (array_intersect($relevant, $topK) !== []) {
                $hits += 1.0;
            }
        }

        return $hits / count($qrels);
    }

    /**
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     */
    public static function mrr(array $qrels, array $ranked): float
    {
        if ($qrels === []) {
            return 0.0;
        }

        $reciprocals = 0.0;

        foreach ($qrels as $queryId => $relevant) {
            $reciprocals += self::reciprocalRank($ranked[$queryId] ?? [], $relevant);
        }

        return $reciprocals / count($qrels);
    }

    /**
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     */
    public static function ndcgAtK(array $qrels, array $ranked, int $k): float
    {
        if ($qrels === []) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($qrels as $queryId => $relevant) {
            $total += self::ndcgForQuery(array_slice($ranked[$queryId] ?? [], 0, $k), $relevant, $k);
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
