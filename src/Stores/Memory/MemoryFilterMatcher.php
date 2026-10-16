<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\Memory;

use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\ValueObjects\FilterCondition;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;

/**
 * The filter rules of PgVectorFilterTranslator, evaluated in PHP on decoded metadata.
 */
final class MemoryFilterMatcher
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function matches(?NormalizedFilter $filter, array $metadata): bool
    {
        foreach ($filter === null ? [] : $filter->conditions as $condition) {
            if (! $this->matchesCondition($condition, $metadata)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function matchesCondition(FilterCondition $condition, array $metadata): bool
    {
        $stored = $metadata[$condition->field] ?? null;
        $value = $condition->value;

        return match ($condition->operator) {
            FilterOperator::Equals => $this->equals($stored, $value),
            FilterOperator::In => $this->in($stored, $value),
            FilterOperator::NotEquals => ! $this->equals($stored, $value),
            FilterOperator::NotIn => ! $this->in($stored, $value),
            FilterOperator::Contains => is_array($stored) && array_is_list($stored) && $this->arrayContains($stored, $value),
            FilterOperator::GreaterThan => $this->compare($stored, $value) > 0,
            FilterOperator::GreaterThanOrEqual => ($this->compare($stored, $value) ?? -1) >= 0,
            FilterOperator::LessThan => ($this->compare($stored, $value) ?? 1) < 0,
            FilterOperator::LessThanOrEqual => ($this->compare($stored, $value) ?? 1) <= 0,
        };
    }

    /**
     * @param  list<bool|int|float|string>  $values
     */
    private function in(mixed $stored, array $values): bool
    {
        foreach ($values as $value) {
            if ($this->equals($stored, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Type-exact: a string, number or boolean only matches a field of the same JSON type.
     */
    private function equals(mixed $stored, bool|int|float|string $value): bool
    {
        return $this->isNumber($value)
            ? $this->isNumber($stored) && $stored == $value
            : $stored === $value;
    }

    /**
     * Same JSON type and value, numbers by numeric value (jsonb `@>` semantics).
     *
     * @param  list<mixed>  $items
     */
    private function arrayContains(array $items, bool|int|float|string $value): bool
    {
        foreach ($items as $item) {
            $same = $this->isNumber($value)
                ? $this->isNumber($item) && $item == $value
                : $item === $value;

            if ($same) {
                return true;
            }
        }

        return false;
    }

    /**
     * Spaceship result for a field of the value's type (number, or string in byte order), null otherwise.
     */
    private function compare(mixed $stored, int|float|string $value): ?int
    {
        if (is_string($value)) {
            return is_string($stored) ? strcmp($stored, $value) <=> 0 : null;
        }

        return $this->isNumber($stored) ? $stored <=> $value : null;
    }

    private function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
