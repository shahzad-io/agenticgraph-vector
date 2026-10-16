<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Support;

use DateTimeInterface;
use DateTimeZone;

/**
 * The one supported date format in metadata and filters: UTC ISO 8601 strings, which sort
 * in time order as plain strings.
 */
final class UtcDate
{
    public const FORMAT = 'Y-m-d\TH:i:s\Z';

    public static function format(DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::FORMAT);
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1;
    }
}
