<?php

use AgenticGraph\Vector\Enums\PgVectorMetric;

it('builds score SQL from the same transform as distanceToSimilarity', function () {
    $distance = 'embedding <=> ?::vector';

    expect(PgVectorMetric::Cosine->scoreSqlExpression($distance))
        ->toBe('(1 - (embedding <=> ?::vector))')
        ->and(PgVectorMetric::Cosine->distanceToSimilarity(0.25))->toBe(0.75)
        ->and(PgVectorMetric::L2->scoreSqlExpression($distance))
        ->toBe('(1 / (1 + (embedding <=> ?::vector)))')
        ->and(PgVectorMetric::L2->distanceToSimilarity(1.0))->toBe(0.5)
        ->and(PgVectorMetric::InnerProduct->scoreSqlExpression($distance))
        ->toBe('(-(embedding <=> ?::vector))')
        ->and(PgVectorMetric::InnerProduct->distanceToSimilarity(-0.4))->toBe(0.4);
});

it('maps minScore thresholds to max distance using maxDistanceForMinScore', function () {
    expect(PgVectorMetric::Cosine->maxDistanceForMinScore(0.75))->toBe(0.25)
        ->and(PgVectorMetric::InnerProduct->maxDistanceForMinScore(0.4))->toBe(-0.4);
});
