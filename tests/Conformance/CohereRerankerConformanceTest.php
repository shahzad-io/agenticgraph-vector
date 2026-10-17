<?php

namespace AgenticGraph\Vector\Tests\Conformance;

final class CohereRerankerConformanceTest extends ApiRerankerConformanceTest
{
    protected static function rerankerName(): string
    {
        return 'cohere';
    }

    protected static function providerConfig(): array
    {
        return ['driver' => 'cohere', 'model' => self::env('VECTOR_RERANK_COHERE_MODEL') ?: 'rerank-v4.0-pro', 'api_key' => self::env('COHERE_API_KEY')];
    }

    protected static function skipReason(): ?string
    {
        return self::env('COHERE_API_KEY') === '' ? 'Skipping: COHERE_API_KEY not set (required for the Cohere reranker conformance run).' : null;
    }
}
