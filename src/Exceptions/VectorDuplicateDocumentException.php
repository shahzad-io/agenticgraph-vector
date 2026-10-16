<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Exceptions;

final class VectorDuplicateDocumentException extends VectorQueryException
{
    public static function forId(string $collection, ?string $id): self
    {
        return new self($id === null
            ? "A document id already exists in collection [{$collection}]."
            : "Document [{$id}] already exists in collection [{$collection}].");
    }
}
