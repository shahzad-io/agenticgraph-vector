<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Enums;

use AgenticGraph\Vector\Stores\PgVector\PgVectorMetricSql;

/**
 * @deprecated 0.2.0 Use Metric. Kept as a thin enum (not an alias) so its SQL methods keep working; remove in 1.0.
 */
enum PgVectorMetric: string
{
    case Cosine = 'cosine';
    case L2 = 'l2';
    case InnerProduct = 'inner_product';

    public static function fromString(string $metric): self
    {
        return self::tryFrom($metric) ?? self::Cosine;
    }

    public function metric(): Metric
    {
        return Metric::from($this->value);
    }

    public function distanceOperator(): string
    {
        return PgVectorMetricSql::distanceOperator($this->metric());
    }

    public function indexOps(): string
    {
        return PgVectorMetricSql::indexOps($this->metric());
    }

    public function distanceToSimilarity(float $distance): float
    {
        return $this->metric()->distanceToSimilarity($distance);
    }

    public function scoreSqlExpression(string $distanceExpression): string
    {
        return PgVectorMetricSql::scoreSqlExpression($this->metric(), $distanceExpression);
    }

    public function maxDistanceForMinScore(float $minScore): float
    {
        return $this->metric()->maxDistanceForMinScore($minScore);
    }
}
