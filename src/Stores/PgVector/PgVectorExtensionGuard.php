<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

use AgenticGraph\Vector\Exceptions\VectorExtensionMissingException;
use Illuminate\Database\Connection;
use Throwable;

final class PgVectorExtensionGuard
{
    public function __construct(
        private readonly Connection $connection,
        private readonly bool $attemptInstall = true,
    ) {}

    /** @phpstan-impure */
    public function isInstalled(): bool
    {
        return (bool) $this->connection->scalar(
            "SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'",
        );
    }

    public function ensureAvailable(): void
    {
        if ($this->isInstalled()) {
            return;
        }

        if ($this->attemptInstall) {
            try {
                $this->connection->statement('CREATE EXTENSION IF NOT EXISTS vector');
            } catch (Throwable) {
                // Managed Postgres often blocks CREATE EXTENSION for non-superusers.
            }

            if ($this->isInstalled()) {
                return;
            }
        }

        throw VectorExtensionMissingException::pgvector();
    }
}
