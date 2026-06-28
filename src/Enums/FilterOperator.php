<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Enums;

enum FilterOperator: string
{
    case Equals = 'eq';
    case In = 'in';
}
