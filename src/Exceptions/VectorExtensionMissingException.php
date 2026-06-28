<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Exceptions;

final class VectorExtensionMissingException extends VectorException
{
    public static function pgvector(): self
    {
        return new self(
            'The pgvector extension is not installed. Run `CREATE EXTENSION vector;` or ask your database administrator to enable pgvector.',
        );
    }
}
