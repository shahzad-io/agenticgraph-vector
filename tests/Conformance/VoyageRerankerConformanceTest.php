<?php

namespace AgenticGraph\Vector\Tests\Conformance;

final class VoyageRerankerConformanceTest extends ApiRerankerConformanceTest
{
    protected static function rerankerName(): string
    {
        return 'voyage';
    }

    protected static function providerConfig(): array
    {
        return ['driver' => 'voyage', 'model' => self::env('VECTOR_RERANK_VOYAGE_MODEL') ?: 'rerank-3', 'api_key' => self::env('VOYAGE_API_KEY')];
    }

    protected static function skipReason(): ?string
    {
        return self::env('VOYAGE_API_KEY') === '' ? 'Skipping: VOYAGE_API_KEY not set (required for the Voyage reranker conformance run).' : null;
    }
}
