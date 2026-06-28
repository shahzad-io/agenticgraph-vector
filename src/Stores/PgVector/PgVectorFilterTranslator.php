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
        $path = "metadata->>'{$field}'";

        return match ($condition->operator) {
            FilterOperator::Equals => [
                "{$path} = ?",
                [$this->scalarValue($condition->value)],
            ],
            FilterOperator::In => $this->translateIn($path, $condition->value),
        };
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function translateIn(string $path, mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            throw new VectorInvalidFilterException('The In filter operator requires a non-empty array of values.');
        }

        $placeholders = implode(', ', array_fill(0, count($value), '?'));

        return [
            "{$path} IN ({$placeholders})",
            array_map(fn (mixed $item): string => $this->scalarValue($item), $value),
        ];
    }

    private function scalarValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        throw new VectorInvalidFilterException('Filter values must be scalar.');
    }

    private function assertSafeField(string $field): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            throw new VectorInvalidFilterException("Invalid metadata filter field [{$field}].");
        }

        return $field;
    }
}
