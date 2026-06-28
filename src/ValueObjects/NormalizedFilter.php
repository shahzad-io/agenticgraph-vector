<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

use AgenticGraph\Vector\Enums\FilterOperator;

final readonly class NormalizedFilter
{
    /**
     * @param  list<FilterCondition>  $conditions
     */
    public function __construct(
        public array $conditions,
    ) {}

    /**
     * @param  list<array{field: string, operator: FilterOperator|string, value: mixed}>  $whereConditions
     */
    public static function from(array $whereConditions): self
    {
        $conditions = array_map(
            static function (array $condition): FilterCondition {
                $operator = $condition['operator'];

                if (is_string($operator)) {
                    $operator = FilterOperator::from($operator);
                }

                return new FilterCondition(
                    field: $condition['field'],
                    operator: $operator,
                    value: $condition['value'],
                );
            },
            $whereConditions,
        );

        return new self($conditions);
    }
}
