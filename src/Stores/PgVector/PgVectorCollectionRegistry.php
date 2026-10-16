<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

use AgenticGraph\Vector\Enums\PgVectorMetric;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorInvalidFilterException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;

final class PgVectorCollectionRegistry
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $registryTable,
    ) {}

    /**
     * Create the registry table, or add `index_options` to a registry created before B3.
     */
    public function ensureRegistryTable(): void
    {
        $schema = Schema::connection($this->connection->getName());

        if (! $schema->hasTable($this->registryTable)) {
            $this->connection->statement(
                "CREATE TABLE {$this->registryTable} (
                    name VARCHAR(255) PRIMARY KEY,
                    dimensions INTEGER NOT NULL,
                    metric VARCHAR(32) NOT NULL,
                    storage_identifier VARCHAR(255) NOT NULL UNIQUE,
                    index_options JSONB NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )",
            );

            return;
        }

        if (! $schema->hasColumn($this->registryTable, 'index_options')) {
            $this->connection->statement("ALTER TABLE {$this->registryTable} ADD COLUMN IF NOT EXISTS index_options JSONB NULL");
        }
    }

    /**
     * @param  array<string, mixed>  $indexOptions  the requested index (PgVectorIndex::toArray())
     */
    public function register(CollectionDefinition $definition, int $dimensions, PgVectorMetric $metric, array $indexOptions = []): string
    {
        $this->assertValidCollectionName($definition->name);

        if ($this->exists($definition->name)) {
            throw new VectorQueryException("Collection [{$definition->name}] already exists.");
        }

        $storageIdentifier = $this->storageIdentifier($definition->name);

        $this->connection->insert(
            "INSERT INTO {$this->registryTable} (name, dimensions, metric, storage_identifier, index_options) VALUES (?, ?, ?, ?, ?::jsonb)",
            [$definition->name, $dimensions, $metric->value, $storageIdentifier, $indexOptions === [] ? null : json_encode($indexOptions, JSON_THROW_ON_ERROR)],
        );

        return $storageIdentifier;
    }

    public function exists(string $collection): bool
    {
        $this->assertValidCollectionName($collection);

        return (bool) $this->connection->scalar(
            "SELECT COUNT(*) FROM {$this->registryTable} WHERE name = ?",
            [$collection],
        );
    }

    /**
     * @return list<string>
     */
    public function listCollectionNames(): array
    {
        /** @var list<string> $names */
        $names = $this->connection->table($this->registryTable)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return $names;
    }

    public function remove(string $collection): void
    {
        if (! $this->exists($collection)) {
            throw new VectorCollectionNotFoundException("Collection [{$collection}] was not found.");
        }

        $this->connection->delete(
            "DELETE FROM {$this->registryTable} WHERE name = ?",
            [$collection],
        );
    }

    /**
     * @return array{dimensions: int, metric: PgVectorMetric, storage_identifier: string, index_options: array{type?: string, options?: array<string, int>}|null}
     */
    public function resolve(string $collection): array
    {
        $this->assertValidCollectionName($collection);

        /** @var object{dimensions: int|string, metric: string, storage_identifier: string, index_options: string|null}|null $row */
        $row = $this->connection->selectOne(
            "SELECT dimensions, metric, storage_identifier, index_options FROM {$this->registryTable} WHERE name = ?",
            [$collection],
        );

        if ($row === null) {
            throw new VectorCollectionNotFoundException("Collection [{$collection}] was not found.");
        }

        return [
            'dimensions' => (int) $row->dimensions,
            'metric' => PgVectorMetric::fromString($row->metric),
            'storage_identifier' => $row->storage_identifier,
            'index_options' => $row->index_options !== null ? json_decode($row->index_options, true, flags: JSON_THROW_ON_ERROR) : null,
        ];
    }

    public function storageIdentifier(string $collection): string
    {
        $this->assertValidCollectionName($collection);

        return 'vcol_'.substr(hash('sha256', $collection), 0, 32);
    }

    public function qualifiedStorageName(string $storageIdentifier): string
    {
        if (! preg_match('/^vcol_[a-f0-9]{32}$/', $storageIdentifier)) {
            throw new VectorInvalidFilterException('Invalid collection storage identifier.');
        }

        return $storageIdentifier;
    }

    private function assertValidCollectionName(string $collection): void
    {
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $collection)) {
            throw new VectorInvalidFilterException("Invalid collection name [{$collection}].");
        }
    }
}
