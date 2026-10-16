<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\Exceptions\VectorInvalidFilterException;
use AgenticGraph\Vector\Support\UtcDate;
use DateTimeInterface;

/**
 * One validated filter condition. Shape rules live here so every store sees the same input:
 * top-level field names, scalar values, one scalar type per list, ranges on numbers or UTC ISO dates.
 * DateTimeInterface values are normalized to UtcDate::FORMAT.
 */
final readonly class FilterCondition
{
    public mixed $value;

    public function __construct(
        public string $field,
        public FilterOperator $operator,
        mixed $value,
    ) {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            throw new VectorInvalidFilterException("Invalid metadata filter field [{$field}].");
        }

        $this->value = match ($operator) {
            FilterOperator::In, FilterOperator::NotIn => self::validList($operator, $value),
            FilterOperator::Equals, FilterOperator::NotEquals, FilterOperator::Contains => self::validScalar($value),
            default => self::validRangeValue($operator, $value),
        };
    }

    private static function validScalar(mixed $value): bool|int|float|string
    {
        if ($value instanceof DateTimeInterface) {
            return UtcDate::format($value);
        }

        if (! is_scalar($value)) {
            throw new VectorInvalidFilterException('Filter values must be scalar.');
        }

        return $value;
    }

    /**
     * @return list<bool|int|float|string>
     */
    private static function validList(FilterOperator $operator, mixed $value): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw new VectorInvalidFilterException("The {$operator->value} filter operator requires a non-empty list of values.");
        }

        $values = array_map(self::validScalar(...), $value);
        $type = self::scalarType($values[0]);

        foreach ($values as $item) {
            if (self::scalarType($item) !== $type) {
                throw new VectorInvalidFilterException("{$operator->value} filter values must share the same scalar type.");
            }
        }

        return $values;
    }

    private static function validRangeValue(FilterOperator $operator, mixed $value): int|float|string
    {
        $value = $value instanceof DateTimeInterface ? UtcDate::format($value) : $value;

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && UtcDate::isValid($value)) {
            return $value;
        }

        throw new VectorInvalidFilterException(
            "The {$operator->value} filter operator takes a number, a DateTimeInterface or a UTC ISO 8601 string (Y-m-d\\TH:i:s\\Z).",
        );
    }

    private static function scalarType(bool|int|float|string $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_string($value) => 'string',
            default => 'number',
        };
    }
}
