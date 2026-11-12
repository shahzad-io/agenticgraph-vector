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
     * nDCG@k with linear gains: a document's grade (1 when $grades has none, so binary sets score as before).
     *
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     * @param  array<string, array<string, int>>  $grades  query id => document id => grade
     */
    public static function ndcgAtK(array $qrels, array $ranked, int $k, array $grades = []): float
    {
        return $qrels === [] ? 0.0 : array_sum(self::ndcgPerQuery($qrels, $ranked, $k, $grades)) / count($qrels);
    }

    /**
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     * @param  Closure(list<string>, list<string>): float  $score
     */
    private static function average(array $qrels, array $ranked, Closure $score): float
    {
        return $qrels === [] ? 0.0 : array_sum(self::perQuery($qrels, $ranked, $score)) / count($qrels);
    }

    /**
     * nDCG@k of each query, in qrels order (for paired comparisons).
     *
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     * @param  array<string, array<string, int>>  $grades
     * @return array<string, float>
     */
    public static function ndcgPerQuery(array $qrels, array $ranked, int $k, array $grades = []): array
    {
        $scores = [];

        foreach ($qrels as $queryId => $relevant) {
            $scores[(string) $queryId] = self::ndcgForQuery(array_slice($ranked[$queryId] ?? [], 0, $k), $relevant, $k, $grades[$queryId] ?? []);
        }

        return $scores;
    }

    /**
     * recall@k of each query, in qrels order (for paired comparisons).
     *
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     * @return array<string, float>
     */
    public static function recallPerQuery(array $qrels, array $ranked, int $k): array
    {
        return self::perQuery($qrels, $ranked, static fn (array $relevant, array $rankedIds): float => $relevant === []
            ? 0.0
            : count(array_intersect($relevant, array_slice($rankedIds, 0, $k))) / count($relevant));
    }

    /**
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     * @param  Closure(list<string>, list<string>): float  $score
     * @return array<string, float>
     */
    private static function perQuery(array $qrels, array $ranked, Closure $score): array
    {
        $scores = [];

        foreach ($qrels as $queryId => $relevant) {
            $scores[(string) $queryId] = $score($relevant, $ranked[$queryId] ?? []);
        }

        return $scores;
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
     * @param  array<string, int>  $grades  document id => grade
     */
    private static function ndcgForQuery(array $rankedIds, array $relevantIds, int $k, array $grades = []): float
    {
        $dcg = 0.0;

        foreach ($rankedIds as $index => $docId) {
            if (! in_array($docId, $relevantIds, true)) {
                continue;
            }

            $dcg += ($grades[$docId] ?? 1) / log($index + 2, 2);
        }

        $ideal = array_map(static fn (string $docId): int => $grades[$docId] ?? 1, $relevantIds);
        rsort($ideal);
        $idcg = 0.0;

        foreach (array_slice($ideal, 0, $k) as $i => $grade) {
            $idcg += $grade / log($i + 2, 2);
        }

        if ($idcg === 0.0) {
            return 0.0;
        }

        return $dcg / $idcg;
    }
}
