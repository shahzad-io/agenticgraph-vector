<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

final class PgVectorVectorFormatter
{
    /**
     * Nine significant digits: pgvector stores float32, which nine digits write exactly.
     *
     * @param  list<float>  $vector
     */
    public static function format(array $vector): string
    {
        $parts = array_map(
            static fn (float $value): string => is_finite($value) ? sprintf('%.9g', $value) : '0',
            $vector,
        );

        return '['.implode(',', $parts).']';
    }
}
