<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

use AgenticGraph\Vector\Enums\FieldType;
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
 *
 * A declared field (CollectionDefinition::$filterable) is validated on write, so it gets a plain predicate that its
 * field index (indexSql()) serves: no CASE guard. A missing field or null is SQL NULL in both forms, so ne/not_in
 * (IS NOT TRUE) still match it and no other operator does.
 */
final class PgVectorFilterTranslator
{
    /**
     * @param  array<string, FieldType>  $declared  the collection's declared fields
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function translate(?NormalizedFilter $filter, array $declared = []): array
    {
        if ($filter === null || $filter->conditions === []) {
            return ['sql' => '1 = 1', 'bindings' => []];
        }

        $clauses = [];
        $bindings = [];

        foreach ($filter->conditions as $condition) {
            $type = $declared[$condition->field] ?? null;
            [$clause, $conditionBindings] = $type === null ? $this->translateCondition($condition) : $this->declaredCondition($condition, $type);
            $clauses[] = $clause;
            array_push($bindings, ...$conditionBindings);
        }

        return [
            'sql' => implode(' AND ', $clauses),
            'bindings' => $bindings,
        ];
    }

    /**
     * True when every condition is on a declared field (so every predicate has a field index).
     *
     * @param  array<string, FieldType>  $declared
     */
    public static function allDeclared(?NormalizedFilter $filter, array $declared): bool
    {
        foreach ($filter === null ? [] : $filter->conditions as $condition) {
            if (! isset($declared[$condition->field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The field index of a declared field: a btree on the expression of plain(), a GIN index for a keyword list.
     * The name is the table name, `_f` and 10 hex of sha256(field): with the longest table_prefix it is 61 bytes.
     */
    public static function indexSql(string $storage, string $field, FieldType $type): string
    {
        $name = "{$storage}_f".substr(hash('sha256', $field), 0, 10);

        return $type === FieldType::KeywordList
            ? "CREATE INDEX IF NOT EXISTS {$name} ON {$storage} USING gin ((metadata->'{$field}') jsonb_path_ops)"
            : "CREATE INDEX IF NOT EXISTS {$name} ON {$storage} (".self::plain($field, $type).')';
    }

    /**
     * The plain expression of a declared scalar field. Datetime compares in byte order (C collation), which is
     * time order for the UTC form the write stores.
     */
    private static function plain(string $field, FieldType $type): string
    {
        return match ($type) {
            FieldType::Keyword, FieldType::KeywordList => "(metadata->>'{$field}')", // a keyword list only takes contains
            FieldType::Integer, FieldType::Float => "((metadata->>'{$field}')::numeric)",
            FieldType::Bool => "((metadata->>'{$field}')::boolean)",
            FieldType::Datetime => "((metadata->>'{$field}') COLLATE \"C\")",
        };
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function declaredCondition(FilterCondition $condition, FieldType $type): array
    {
        $field = $condition->field;
        $value = $condition->value;

        if ($condition->operator === FilterOperator::Contains) {
            return ["(metadata->'{$field}') @> ?::jsonb", [json_encode([$value], JSON_THROW_ON_ERROR)]];
        }

        $expression = self::plain($field, $type);
        $in = static fn (array $values): array => ["{$expression} IN (".implode(', ', array_fill(0, count($values), '?')).')', array_values($values)];

        return match ($condition->operator) {
            FilterOperator::Equals => ["{$expression} = ?", [$value]],
            FilterOperator::NotEquals => ["({$expression} = ?) IS NOT TRUE", [$value]],
            FilterOperator::In => $in($value),
            FilterOperator::NotIn => $this->negate($in($value)),
            FilterOperator::GreaterThan => ["{$expression} > ?", [$value]],
            FilterOperator::GreaterThanOrEqual => ["{$expression} >= ?", [$value]],
            FilterOperator::LessThan => ["{$expression} < ?", [$value]],
            FilterOperator::LessThanOrEqual => ["{$expression} <= ?", [$value]],
        };
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
