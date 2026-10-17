<?php

namespace AgenticGraph\Vector\Tests\Conformance;

final class PrismRerankerConformanceTest extends ApiRerankerConformanceTest
{
    protected static function rerankerName(): string
    {
        return 'llm';
    }

    protected static function providerConfig(): array
    {
        return ['driver' => 'prism', 'provider' => 'openai', 'model' => self::env('VECTOR_RERANK_PRISM_MODEL') ?: 'gpt-4.1-mini', 'api_key' => self::env('OPENAI_API_KEY'), 'max_characters' => 1500];
    }

    protected static function skipReason(): ?string
    {
        if (self::env('VECTOR_PRISM_INTEGRATION') !== 'true') {
            return 'Skipping: VECTOR_PRISM_INTEGRATION is not true (real Prism reranker conformance disabled).';
        }

        return self::env('OPENAI_API_KEY') === '' ? 'Skipping: OPENAI_API_KEY not set (required for the Prism reranker conformance run).' : null;
    }
}
