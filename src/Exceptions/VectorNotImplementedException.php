<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Exceptions;

use ReflectionClass;

final class VectorNotImplementedException extends VectorException
{
    public static function forMethod(string $method): self
    {
        return new self(self::formatMethod($method).' is not yet implemented.');
    }

    private static function formatMethod(string $method): string
    {
        if (! str_contains($method, '::')) {
            return $method;
        }

        [$class, $name] = explode('::', $method, 2);

        return (new ReflectionClass($class))->getShortName().'::'.$name.'()';
    }
}
