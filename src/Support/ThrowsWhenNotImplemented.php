<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Support;

use AgenticGraph\Vector\Exceptions\VectorNotImplementedException;

trait ThrowsWhenNotImplemented
{
    /**
     * @return never
     */
    protected function throwNotImplemented(string $method): void
    {
        throw VectorNotImplementedException::forMethod($method);
    }
}
