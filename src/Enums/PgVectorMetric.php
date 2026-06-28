<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Enums;

enum PgVectorMetric: string
{
    case Cosine = 'cosine';
    case L2 = 'l2';
    case InnerProduct = 'inner_product';

    public static function fromString(string $metric): self
    {
        return self::tryFrom($metric) ?? self::Cosine;
    }

    public function distanceOperator(): string
    {
        return match ($this) {
            self::Cosine => '<=>',
            self::L2 => '<->',
            self::InnerProduct => '<#>',
        };
    }

    public function indexOps(): string
    {
        return match ($this) {
            self::Cosine => 'vector_cosine_ops',
            self::L2 => 'vector_l2_ops',
            self::InnerProduct => 'vector_ip_ops',
        };
    }

    public function distanceToSimilarity(float $distance): float
    {
        return match ($this) {
            self::Cosine => 1.0 - $distance,
            self::L2 => 1.0 / (1.0 + $distance),
            self::InnerProduct => -$distance,
        };
    }

    public function maxDistanceForMinScore(float $minScore): float
    {
        return match ($this) {
            self::Cosine => 1.0 - $minScore,
            self::L2 => (1.0 / max($minScore, 0.0001)) - 1.0,
            self::InnerProduct => -$minScore,
        };
    }
}
