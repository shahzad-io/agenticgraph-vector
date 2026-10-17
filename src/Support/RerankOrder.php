<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Support;

use AgenticGraph\Vector\ValueObjects\SearchHit;

/**
 * Turns a reranker's (input index, score) list into hits, shared by every driver: out-of-range and repeated
 * indices are dropped, input hits the reranker left out follow in input order with a null rerank score, the
 * order is by score (best first, ties in input order), and the list is cut to topN.
 */
final class RerankOrder
{
    /**
     * @param  list<SearchHit>  $hits
     * @param  iterable<array{index: mixed, score: mixed}>  $ranked
     * @return list<SearchHit>
     */
    public static function apply(array $hits, iterable $ranked, int $topN): array
    {
        $scores = [];

        foreach ($ranked as $entry) {
            $index = $entry['index'];

            if (is_numeric($index) && (int) $index == $index && isset($hits[(int) $index]) && ! array_key_exists((int) $index, $scores) && is_numeric($entry['score'])) {
                $scores[(int) $index] = (float) $entry['score'];
            }
        }

        $order = array_keys($hits);
        usort($order, static fn (int $a, int $b): int => ($scores[$b] ?? -INF) <=> ($scores[$a] ?? -INF) ?: $a <=> $b);

        return array_map(
            static fn (int $index): SearchHit => self::withScore($hits[$index], $scores[$index] ?? null),
            array_slice($order, 0, max(0, $topN)),
        );
    }

    public static function withScore(SearchHit $hit, ?float $score): SearchHit
    {
        return new SearchHit(
            id: $hit->id,
            score: $hit->score,
            content: $hit->content,
            metadata: $hit->metadata,
            vector: $hit->vector,
            scores: [...($hit->scores ?? ['vector' => $hit->score]), 'rerank' => $score],
        );
    }
}
