<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\Exceptions\VectorInvalidFilterException;
use AgenticGraph\Vector\ValueObjects\FilterCondition;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;

final class PgVectorFilterTranslator
{
    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function translate(?NormalizedFilter $filter): array
    {
        if ($filter === null || $filter->conditions === []) {
            return ['sql' => '1 = 1', 'bindings' => []];
        }

        $clauses = [];
        $bindings = [];

        foreach ($filter->conditions as $condition) {
            [$clause, $conditionBindings] = $this->translateCondition($condition);
            $clauses[] = $clause;
            array_push($bindings, ...$conditionBindings);
        }

        return [
            'sql' => implode(' AND ', $clauses),
            'bindings' => $bindings,
        ];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function translateCondition(FilterCondition $condition): array
    {
        $field = $this->assertSafeField($condition->field);

        return match ($condition->operator) {
            FilterOperator::Equals => $this->translateEquals($field, $condition->value),
            FilterOperator::In => $this->translateIn($field, $condition->value),
        };
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function translateEquals(string $field, mixed $value): array
    {
        if (is_bool($value)) {
            return [
                "(metadata->'{$field}')::boolean = ?",
                [$value],
            ];
        }

        if (is_int($value) || is_float($value)) {
            return [
                "(metadata->'{$field}')::numeric = ?",
                [$value],
            ];
        }

        if (is_string($value)) {
            return [
                "metadata->>'{$field}' = ?",
                [$value],
            ];
        }

        throw new VectorInvalidFilterException('Filter values must be scalar.');
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function translateIn(string $field, mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            throw new VectorInvalidFilterException('The In filter operator requires a non-empty array of values.');
        }

        $first = $value[0];

        if (is_bool($first)) {
            foreach ($value as $item) {
                if (! is_bool($item)) {
                    throw new VectorInvalidFilterException('In filter values must share the same scalar type.');
                }
            }

            $placeholders = implode(', ', array_fill(0, count($value), '?'));

            return [
                "(metadata->'{$field}')::boolean IN ({$placeholders})",
                $value,
            ];
        }

        if (is_int($first) || is_float($first)) {
            foreach ($value as $item) {
                if (! is_int($item) && ! is_float($item)) {
                    throw new VectorInvalidFilterException('In filter values must share the same scalar type.');
                }
            }

            $placeholders = implode(', ', array_fill(0, count($value), '?'));

            return [
                "(metadata->'{$field}')::numeric IN ({$placeholders})",
                $value,
            ];
        }

        if (is_string($first)) {
            foreach ($value as $item) {
                if (! is_string($item)) {
                    throw new VectorInvalidFilterException('In filter values must share the same scalar type.');
                }
            }

            $placeholders = implode(', ', array_fill(0, count($value), '?'));

            return [
                "metadata->>'{$field}' IN ({$placeholders})",
                $value,
            ];
        }

        throw new VectorInvalidFilterException('In filter values must be scalar.');
    }

    private function assertSafeField(string $field): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            throw new VectorInvalidFilterException("Invalid metadata filter field [{$field}].");
        }

        return $field;
    }
}
