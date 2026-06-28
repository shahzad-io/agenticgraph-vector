<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

use AgenticGraph\Vector\Enums\FilterOperator;

final readonly class FilterCondition
{
    public function __construct(
        public string $field,
        public FilterOperator $operator,
        public mixed $value,
    ) {}
}
