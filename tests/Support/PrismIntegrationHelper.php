<?php

namespace AgenticGraph\Vector\Tests\Support;

final class PrismIntegrationHelper
{
    public static function isAvailable(): bool
    {
        if (self::env('VECTOR_PRISM_INTEGRATION', 'false') !== 'true') {
            return false;
        }

        $apiKey = self::env('OPENAI_API_KEY', '');

        return $apiKey !== '';
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
