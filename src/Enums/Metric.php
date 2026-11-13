<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Enums;

/**
 * The similarity metric of a collection. A store works with distances internally; the score it returns is:
 *
 * | Metric        | Score               | Range            |
 * |---------------|---------------------|------------------|
 * | cosine        | cosine similarity   | -1 to 1          |
 * | l2            | 1 / (1 + distance)  | above 0, up to 1 |
 * | inner_product | the dot product     | unbounded        |
 *
 * Distances: cosine 1 - similarity, l2 the Euclidean distance, inner_product the negative dot product.
 */
enum Metric: string
{
    case Cosine = 'cosine';
    case L2 = 'l2';
    case InnerProduct = 'inner_product';

    public function distanceToSimilarity(float $distance): float
    {
        return match ($this) {
            self::Cosine => 1.0 - $distance,
            self::L2 => 1.0 / (1.0 + $distance),
            self::InnerProduct => -$distance,
        };
    }

    /**
     * The largest distance whose score is still >= $minScore. On l2 a $minScore at or below 0 counts as 0.0001.
     */
    public function maxDistanceForMinScore(float $minScore): float
    {
        return match ($this) {
            self::Cosine => 1.0 - $minScore,
            self::L2 => (1.0 / max($minScore, 0.0001)) - 1.0,
            self::InnerProduct => -$minScore,
        };
    }
}
