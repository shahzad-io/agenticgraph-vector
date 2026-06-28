<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

final class PgVectorVectorFormatter
{
    /**
     * @param  list<float>  $vector
     */
    public static function format(array $vector): string
    {
        $parts = array_map(
            static fn (float $value): string => is_finite($value) ? (string) $value : '0',
            $vector,
        );

        return '['.implode(',', $parts).']';
    }
}
