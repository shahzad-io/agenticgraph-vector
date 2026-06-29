<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Facades\Embeddings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OllamaBenchmarkHelper
{
    public const MODEL = 'mxbai-embed-large';

    public const DIMENSIONS = 1024;

    public static function guardOrSkip(object $test): void
    {
        if (! PgvectorTestHelper::isAvailable()) {
            $test->markTestSkipped(PgvectorTestHelper::skipReason());
        }

        if (self::env('VECTOR_BENCHMARK', 'false') !== 'true') {
            $test->markTestSkipped('Skipping: VECTOR_BENCHMARK is not true.');
        }

        if (! self::isOllamaReachable()) {
            $test->markTestSkipped('Skipping: Ollama is not reachable at '.self::ollamaUrl());
        }

        if (! self::hasEmbeddingModel()) {
            $test->markTestSkipped('Skipping: Ollama model ['.self::MODEL.'] is not pulled.');
        }
    }

    public static function configureOllamaEmbeddings(Application $app): void
    {
        config()->set('prism.providers.ollama.url', self::ollamaUrl());
        config()->set('vector.embeddings.default', 'prism');
        config()->set('vector.embeddings.providers.prism', [
            'driver' => 'prism',
            'provider' => 'ollama',
            'model' => self::MODEL,
            'dimensions' => self::DIMENSIONS,
        ]);

        $app->forgetInstance(EmbeddingsManager::class);
        Embeddings::clearResolvedInstance(EmbeddingsManager::class);

        $dimensions = Embeddings::using('prism')->dimensions();

        if ($dimensions !== self::DIMENSIONS) {
            throw new \RuntimeException(
                'Expected embedding dimensions '.self::DIMENSIONS.", got {$dimensions}.",
            );
        }
    }

    public static function isOllamaReachable(): bool
    {
        try {
            $response = Http::timeout(3)->get(self::ollamaUrl().'/api/tags');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public static function hasEmbeddingModel(): bool
    {
        try {
            $response = Http::timeout(3)->get(self::ollamaUrl().'/api/tags');

            if (! $response->successful()) {
                return false;
            }

            $models = collect($response->json('models', []))
                ->pluck('name')
                ->map(fn (string $name): string => explode(':', $name)[0]);

            return $models->contains(self::MODEL);
        } catch (Throwable) {
            return false;
        }
    }

    public static function ollamaUrl(): string
    {
        return self::env('OLLAMA_URL', 'http://127.0.0.1:11434');
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
