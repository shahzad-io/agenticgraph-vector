<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Exceptions;

use Throwable;

/**
 * An embedding request failed. retryable: a 429, a 5xx or a connection error or timeout (after the provider's own
 * retries), so the same request may succeed later; a queued ingest batch retries only these. status: the
 * provider's HTTP status, null without a response.
 */
final class VectorEmbeddingException extends VectorException
{
    public function __construct(
        string $message = '',
        ?Throwable $previous = null,
        public readonly bool $retryable = false,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
