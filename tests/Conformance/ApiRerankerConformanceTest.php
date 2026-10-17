<?php

namespace AgenticGraph\Vector\Tests\Conformance;

use AgenticGraph\Vector\Contracts\RerankerContract;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Testing\RerankerConformanceTests;
use AgenticGraph\Vector\Tests\TestCase;

/**
 * The reranker conformance suite against the real Cohere, Voyage and Prism (OpenAI) APIs. Gated: Cohere needs
 * COHERE_API_KEY, Voyage VOYAGE_API_KEY, Prism VECTOR_PRISM_INTEGRATION=true and OPENAI_API_KEY. A skip here
 * is a missing key, not a pass. The models are this suite's choice (the package has no default).
 */
abstract class ApiRerankerConformanceTest extends TestCase
{
    use RerankerConformanceTests;

    abstract protected static function rerankerName(): string;

    /** @return array<string, mixed> */
    abstract protected static function providerConfig(): array;

    abstract protected static function skipReason(): ?string;

    protected function setUp(): void
    {
        parent::setUp();

        $reason = static::skipReason();

        if ($reason !== null) {
            $this->markTestSkipped($reason);
        }

        config(['vector.rerank.providers.'.static::rerankerName() => static::providerConfig(), 'vector.rerank.fail_open' => false, 'vector.rerank.timeout' => 60]);
    }

    protected function makeReranker(): RerankerContract
    {
        return Vector::reranker(static::rerankerName());
    }

    protected static function env(string $key): string
    {
        return (string) getenv($key);
    }
}
