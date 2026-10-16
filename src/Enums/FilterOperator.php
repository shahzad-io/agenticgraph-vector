<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Enums;

use AgenticGraph\Vector\Exceptions\VectorInvalidFilterException;

enum FilterOperator: string
{
    case Equals = 'eq';
    case NotEquals = 'ne';
    case In = 'in';
    case NotIn = 'not_in';
    case Contains = 'contains';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';

    /**
     * An operator from its value (`gte`) or its symbol (`>=`), as accepted by Builder::where().
     */
    public static function fromInput(string $operator): self
    {
        return match ($operator) {
            '=', '==' => self::Equals,
            '!=', '<>' => self::NotEquals,
            '>' => self::GreaterThan,
            '>=' => self::GreaterThanOrEqual,
            '<' => self::LessThan,
            '<=' => self::LessThanOrEqual,
            default => self::tryFrom($operator)
                ?? throw new VectorInvalidFilterException("Unknown filter operator [{$operator}]."),
        };
    }

    /**
     * The capability a store needs for this operator, or null when every store supports it.
     */
    public function capability(): ?StoreCapability
    {
        return match ($this) {
            self::Equals, self::In => null,
            self::NotEquals, self::NotIn => StoreCapability::FilterNot,
            self::Contains => StoreCapability::FilterContains,
            self::GreaterThan, self::GreaterThanOrEqual, self::LessThan, self::LessThanOrEqual => StoreCapability::FilterRange,
        };
    }

    public function isRange(): bool
    {
        return $this->capability() === StoreCapability::FilterRange;
    }
}
