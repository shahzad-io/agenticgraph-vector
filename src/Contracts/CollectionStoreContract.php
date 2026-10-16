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

    /**
     * The physical collection a name points to (itself unless it is an alias), its width, metric, index
     * (null when the store has none), recorded embeddings (null when unknown) and keyword settings (null without
     * keyword search). Throws VectorCollectionNotFoundException.
     *
     * @return array{collection: string, dimensions: int, metric: string, index: array{type: string, options: array<string, int>}|null, embedding: array{provider: string, model: string, dimensions: int}|null, keyword: array{languages: array<string, string>, language_field: string}|null}
     */
    public function describeCollection(string $name): array;
}
