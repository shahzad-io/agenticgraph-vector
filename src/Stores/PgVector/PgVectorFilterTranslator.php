<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\ValueObjects\FilterCondition;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;

/**
 * JSONB SQL for validated filter conditions (FilterCondition owns the shape rules).
 *
 * - eq/in: type-exact. A string, number or boolean compares only against a field of the same JSON
 *   type (CASE guard, so a field of another type never matches and never errors).
 * - ne/not_in: the exact complement of eq/in (`IS NOT TRUE`), so a document without the field matches.
 * - contains: `@>` on a one-element array, so only array fields match.
 * - gt/gte/lt/lte: numbers against number fields, UTC ISO strings against string fields (byte order).
 */
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
        $field = $condition->field;
        $value = $condition->value;

        return match ($condition->operator) {
            FilterOperator::Equals => $this->equals($field, [$value], '='),
            FilterOperator::In => $this->equals($field, $value, 'IN'),
            FilterOperator::NotEquals => $this->negate($this->equals($field, [$value], '=')),
            FilterOperator::NotIn => $this->negate($this->equals($field, $value, 'IN')),
            FilterOperator::Contains => [
                'metadata @> ?::jsonb',
                [json_encode([$field => [$value]], JSON_THROW_ON_ERROR)],
            ],
            FilterOperator::GreaterThan => $this->range($field, '>', $value),
            FilterOperator::GreaterThanOrEqual => $this->range($field, '>=', $value),
            FilterOperator::LessThan => $this->range($field, '<', $value),
            FilterOperator::LessThanOrEqual => $this->range($field, '<=', $value),
        };
    }

    /**
     * @param  list<bool|int|float|string>  $values  one scalar type (validated)
     * @return array{0: string, 1: list<mixed>}
     */
    private function equals(string $field, array $values, string $comparison): array
    {
        $first = $values[0];
        $placeholders = $comparison === 'IN' ? '('.implode(', ', array_fill(0, count($values), '?')).')' : '?';

        $expression = match (true) {
            is_bool($first) => $this->typed($field, 'boolean', "(metadata->'{$field}')::boolean"),
            is_string($first) => $this->typed($field, 'string', "metadata->>'{$field}'"),
            default => $this->typed($field, 'number', "(metadata->'{$field}')::numeric"),
        };

        return ["{$expression} {$comparison} {$placeholders}", $values];
    }

    /**
     * @param  array{0: string, 1: list<mixed>}  $translated
     * @return array{0: string, 1: list<mixed>}
     */
    private function negate(array $translated): array
    {
        return ["({$translated[0]}) IS NOT TRUE", $translated[1]];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function range(string $field, string $comparison, int|float|string $value): array
    {
        $expression = is_string($value)
            ? $this->typed($field, 'string', "metadata->>'{$field}'").' COLLATE "C"'
            : $this->typed($field, 'number', "(metadata->'{$field}')::numeric");

        return ["{$expression} {$comparison} ?", [$value]];
    }

    /**
     * The cast only runs for a field of that JSON type; other types give NULL instead of an error.
     */
    private function typed(string $field, string $jsonType, string $cast): string
    {
        return "(CASE WHEN jsonb_typeof(metadata->'{$field}') = '{$jsonType}' THEN {$cast} END)";
    }
}
