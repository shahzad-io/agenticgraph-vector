<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use AgenticGraph\Vector\Contracts\EmbeddingProviderContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\VectorManager;
use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;

final class StandaloneStressHelper
{
    public static function guardOrSkip(object $test): void
    {
        if (! PgvectorTestHelper::isAvailable()) {
            $test->markTestSkipped(PgvectorTestHelper::skipReason());
        }

        if (! PrismIntegrationHelper::isAvailable()) {
            $test->markTestSkipped(PrismIntegrationHelper::skipReason());
        }
    }

    public static function configureRealPrism(Application $app): CountingEmbeddingProvider
    {
        $apiKey = getenv('OPENAI_API_KEY');

        if ($apiKey === false || $apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is required for standalone RAG stress tests.');
        }

        config()->set('vector.embeddings.default', 'prism');
        config()->set('vector.embeddings.providers.prism.api_key', $apiKey);

        $app->forgetInstance(EmbeddingsManager::class);

        $manager = $app->make(EmbeddingsManager::class);
        $inner = $manager->using('prism');
        $counting = new CountingEmbeddingProvider($inner);

        $app->instance(EmbeddingsManager::class, new class($counting) extends EmbeddingsManager
        {
            public function __construct(private readonly EmbeddingProviderContract $provider) {}

            public function using(?string $name = null): EmbeddingProviderContract
            {
                return $this->provider;
            }

            public function driver($driver = null): EmbeddingProviderContract
            {
                return $this->provider;
            }
        });

        self::resetVectorManager($app);

        return $counting;
    }

    public static function resetVectorManager(Application $app): void
    {
        $app->forgetInstance(VectorManager::class);
        Vector::clearResolvedInstance(VectorManager::class);
    }

    /**
     * @return array{package_absent: bool, tracer_unbound: bool, tracing_available: bool}
     */
    public static function agenticGraphStandaloneProof(): array
    {
        $packageAbsent = ! InstalledVersions::isInstalled('shahzad/agenticgraph');
        $tracerUnbound = ! app()->bound('Shahzad\AgenticGraph\Tracing\Tracer');
        $tracingAvailable = TraceSpan::isAgenticGraphTracingAvailable();

        return [
            'package_absent' => $packageAbsent,
            'tracer_unbound' => $tracerUnbound,
            'tracing_available' => $tracingAvailable,
        ];
    }
}
