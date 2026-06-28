<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\HealthCheckResult;

interface CollectionStoreContract
{
    public function createCollection(CollectionDefinition $definition): void;

    public function deleteCollection(string $name): void;

    public function collectionExists(string $name): bool;

    /**
     * @return list<string>
     */
    public function listCollections(): array;

    public function healthCheck(): HealthCheckResult;
}
