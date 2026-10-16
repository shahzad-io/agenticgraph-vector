<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Support;

use AgenticGraph\Vector\Exceptions\VectorInvalidMetadataException;
use DateTimeInterface;

final class MetadataValidator
{
    /**
     * Metadata ready to store: DateTimeInterface values become UTC ISO 8601 strings (UtcDate::FORMAT),
     * then the result is validated.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function normalize(array $metadata): array
    {
        array_walk_recursive($metadata, static function (mixed &$value): void {
            if ($value instanceof DateTimeInterface) {
                $value = UtcDate::format($value);
            }
        });

        $this->validate($metadata);

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function validate(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            $this->validateValue($value, (string) $key);
        }
    }

    private function validateValue(mixed $value, string $path): void
    {
        if ($value === null) {
            return;
        }

        if (is_scalar($value)) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $nestedKey => $nestedValue) {
                $this->validateValue($nestedValue, $path.'.'.$nestedKey);
            }

            return;
        }

        if ($value instanceof \Closure) {
            throw new VectorInvalidMetadataException("Metadata at [{$path}] must not be a closure.");
        }

        if (is_object($value)) {
            throw new VectorInvalidMetadataException("Metadata at [{$path}] must not be an object.");
        }

        if (is_resource($value)) {
            throw new VectorInvalidMetadataException("Metadata at [{$path}] must not be a resource.");
        }

        throw new VectorInvalidMetadataException("Metadata at [{$path}] has an unsupported type.");
    }
}
