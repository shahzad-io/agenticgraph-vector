<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Enums;

use AgenticGraph\Vector\Support\UtcDate;

/**
 * The type of a declared filterable metadata field (CollectionDefinition::$filterable). A store validates a
 * declared field on write, and every store gives the same result for the operators the type allows:
 *
 * | Type        | eq, ne      | in, not_in | gt, gte, lt, lte | contains         |
 * |-------------|-------------|------------|------------------|------------------|
 * | keyword     | yes         | yes        | no               | no               |
 * | integer     | yes         | yes        | yes              | no               |
 * | float       | yes, numeric| yes        | yes              | no               |
 * | bool        | yes         | no         | no               | no               |
 * | datetime    | yes         | no         | yes              | no               |
 * | keyword_list| no          | no         | no               | yes, one element |
 */
enum FieldType: string
{
    case Keyword = 'keyword';
    case Integer = 'integer';
    case Float = 'float';
    case Bool = 'bool';
    case Datetime = 'datetime';
    case KeywordList = 'keyword_list';

    public function allows(FilterOperator $operator): bool
    {
        return match ($operator) {
            FilterOperator::Equals, FilterOperator::NotEquals => $this !== self::KeywordList,
            FilterOperator::In, FilterOperator::NotIn => in_array($this, [self::Keyword, self::Integer, self::Float], true),
            FilterOperator::GreaterThan, FilterOperator::GreaterThanOrEqual, FilterOperator::LessThan, FilterOperator::LessThanOrEqual => in_array($this, [self::Integer, self::Float, self::Datetime], true),
            FilterOperator::Contains => $this === self::KeywordList,
        };
    }

    /**
     * A stored value of this type (after DateTimeInterface values became UTC strings). Null is not checked here.
     */
    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::Keyword => is_string($value),
            self::Integer => is_int($value),
            self::Float => is_int($value) || is_float($value),
            self::Bool => is_bool($value),
            self::Datetime => is_string($value) && UtcDate::isValid($value),
            self::KeywordList => is_array($value) && array_is_list($value) && array_filter($value, is_string(...)) === $value,
        };
    }
}
