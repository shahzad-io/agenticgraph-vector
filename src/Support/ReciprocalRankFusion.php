<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Support;

use AgenticGraph\Vector\Exceptions\VectorQueryException;

/**
 * Weighted reciprocal rank fusion of the dense and keyword candidate lists of a hybrid search: a document's
 * fused score is 1 / (k + dense rank) plus $keywordWeight / (k + keyword rank), over the lists it is in (ranks
 * from 1). At weight 0 the keyword list is left out, so the result is the dense list. Shared by every store.
 */
final class ReciprocalRankFusion
{
    /**
     * @param  list<string>  $dense  ids, best first
     * @param  list<string>  $keyword  ids, best first
     * @return array<string, float> id => fused score, best first; ties by dense rank (dense candidates first), then id
     */
    public static function fuse(array $dense, array $keyword, int $k, float $keywordWeight = 1.0): array
    {
        if (! is_finite($keywordWeight) || $keywordWeight < 0.0) {
            throw new VectorQueryException("The keyword weight must be a finite number of at least 0. Got [{$keywordWeight}].");
        }

        $fused = [];

        foreach ([[$dense, 1.0], [$keywordWeight > 0.0 ? $keyword : [], $keywordWeight]] as [$list, $weight]) {
            foreach ($list as $index => $id) {
                $fused[$id] = ($fused[$id] ?? 0.0) + $weight / ($k + $index + 1);
            }
        }

        $denseRank = array_flip($dense);
        $ids = array_map(strval(...), array_keys($fused));

        usort($ids, static fn (string $a, string $b): int => $fused[$b] <=> $fused[$a]
            ?: ($denseRank[$a] ?? PHP_INT_MAX) <=> ($denseRank[$b] ?? PHP_INT_MAX)
            ?: strcmp($a, $b));

        return array_combine($ids, array_map(static fn (string $id): float => $fused[$id], $ids));
    }
}
