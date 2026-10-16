<?php

namespace AgenticGraph\Vector\Tests\Support;

use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Facades\Embeddings;
use Throwable;

final class PrismIntegrationHelper
{
    private static ?bool $probeSucceeded = null;

    public static function isAvailable(): bool
    {
        if (self::env('VECTOR_PRISM_INTEGRATION', 'false') !== 'true') {
            return false;
        }

        if (self::env('OPENAI_API_KEY', '') === '') {
            return false;
        }

        return self::probeEmbeddingApi();
    }

    public static function skipReason(): string
    {
        if (self::env('VECTOR_PRISM_INTEGRATION', 'false') !== 'true') {
            return 'Skipping: VECTOR_PRISM_INTEGRATION is not true (real Prism integration disabled).';
        }

        if (self::env('OPENAI_API_KEY', '') === '') {
            return 'Skipping: OPENAI_API_KEY not set (required for real Prism embeddings).';
        }

        if (! self::probeEmbeddingApi()) {
            return 'Skipping: Prism embedding API unreachable (probe failed: check OPENAI_API_KEY).';
        }

        return 'Skipping: real Prism integration is not configured.';
    }

    private static function probeEmbeddingApi(): bool
    {
        if (self::$probeSucceeded !== null) {
            return self::$probeSucceeded;
        }

        try {
            if (! function_exists('app')) {
                self::$probeSucceeded = false;

                return false;
            }

            self::useOpenAi();

            app()->forgetInstance(EmbeddingsManager::class);

            Embeddings::using('prism')->embed('integration-availability-probe');

            self::$probeSucceeded = true;
        } catch (Throwable) {
            self::$probeSucceeded = false;
        }

        return self::$probeSucceeded;
    }

    /**
     * Real-Prism test config. The package has no vendor default, so the tests pick OpenAI here.
     */
    public static function useOpenAi(): void
    {
        config([
            'vector.embeddings.default' => 'prism',
            'vector.embeddings.providers.prism.provider' => 'openai',
            'vector.embeddings.providers.prism.model' => 'text-embedding-3-small',
            'vector.embeddings.providers.prism.dimensions' => 1536,
            'vector.embeddings.providers.prism.api_key' => self::env('OPENAI_API_KEY', ''),
        ]);
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }
}
