<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

use AgenticGraph\Vector\Enums\Metric;

/**
 * The pgvector SQL of a metric: its distance operator, index operator class and score expression.
 */
final class PgVectorMetricSql
{
    public static function distanceOperator(Metric $metric): string
    {
        return match ($metric) {
            Metric::Cosine => '<=>',
            Metric::L2 => '<->',
            Metric::InnerProduct => '<#>',
        };
    }

    public static function indexOps(Metric $metric): string
    {
        return match ($metric) {
            Metric::Cosine => 'vector_cosine_ops',
            Metric::L2 => 'vector_l2_ops',
            Metric::InnerProduct => 'vector_ip_ops',
        };
    }

    /**
     * SQL that turns a distance sub-expression into the score of Metric::distanceToSimilarity().
     */
    public static function scoreSqlExpression(Metric $metric, string $distanceExpression): string
    {
        return match ($metric) {
            Metric::Cosine => '(1 - ('.$distanceExpression.'))',
            Metric::L2 => '(1 / (1 + ('.$distanceExpression.')))',
            Metric::InnerProduct => '(-('.$distanceExpression.'))',
        };
    }
}
