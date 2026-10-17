<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

use AgenticGraph\Vector\Enums\PgVectorMetric;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorInvalidFilterException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\MigrationState;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;

/**
 * The registry table (one row per physical collection), its `_aliases` table (alias -> collection)
 * and its `_migrations` table (one row per migration of an alias).
 */
final class PgVectorCollectionRegistry
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $registryTable,
    ) {}

    /**
     * Create the registry, alias and migration tables, or add the columns a registry created before B3
     * (`index_options`), B5 (`embedding`) or B6 (`keyword`) lacks.
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
                    embedding JSONB NULL,
                    keyword JSONB NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )",
            );
        } else {
            foreach (['index_options', 'embedding', 'keyword'] as $column) {
                if (! $schema->hasColumn($this->registryTable, $column)) {
                    $this->connection->statement("ALTER TABLE {$this->registryTable} ADD COLUMN IF NOT EXISTS {$column} JSONB NULL");
                }
            }
        }

        $this->connection->statement(
            "CREATE TABLE IF NOT EXISTS {$this->aliasTable()} (
                alias VARCHAR(255) PRIMARY KEY,
                collection VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
        );

        $this->connection->statement(
            "CREATE TABLE IF NOT EXISTS {$this->migrationTable()} (
                id BIGSERIAL PRIMARY KEY,
                alias VARCHAR(255) NOT NULL,
                source VARCHAR(255) NOT NULL,
                target VARCHAR(255) NOT NULL,
                definition JSONB NOT NULL,
                mode VARCHAR(16) NOT NULL,
                phase VARCHAR(32) NOT NULL,
                last_id TEXT NULL,
                copied INTEGER NOT NULL DEFAULT 0,
                total INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (alias, target)
            )",
        );
    }

    /**
     * @param  array<string, mixed>  $indexOptions  the requested index (PgVectorIndex::toArray())
     * @param  array{provider: string, model: string, dimensions: int}|null  $embedding
     * @param  array{languages: array<string, string>, language_field: string}|null  $keyword
     */
    public function register(CollectionDefinition $definition, int $dimensions, PgVectorMetric $metric, array $indexOptions = [], ?array $embedding = null, ?array $keyword = null): string
    {
        $this->assertValidCollectionName($definition->name);

        if ($this->exists($definition->name)) {
            throw new VectorQueryException("Collection [{$definition->name}] already exists.");
        }

        $storageIdentifier = $this->storageIdentifier($definition->name);

        // An adopted collection keeps the identifier of its old name, which may be this name.
        if ($this->connection->scalar("SELECT COUNT(*) FROM {$this->registryTable} WHERE storage_identifier = ?", [$storageIdentifier])) {
            $storageIdentifier = 'vcol_'.bin2hex(random_bytes(16));
        }

        $this->connection->insert(
            "INSERT INTO {$this->registryTable} (name, dimensions, metric, storage_identifier, index_options, embedding, keyword) VALUES (?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb)",
            [
                $definition->name,
                $dimensions,
                $metric->value,
                $storageIdentifier,
                $indexOptions === [] ? null : json_encode($indexOptions, JSON_THROW_ON_ERROR),
                $embedding === null ? null : json_encode($embedding, JSON_THROW_ON_ERROR),
                $keyword === null ? null : json_encode($keyword, JSON_THROW_ON_ERROR),
            ],
        );

        return $storageIdentifier;
    }

    /**
     * A collection or an alias of that name exists.
     */
    public function exists(string $collection): bool
    {
        $this->assertValidCollectionName($collection);

        return (bool) $this->connection->scalar(
            "SELECT EXISTS (SELECT 1 FROM {$this->registryTable} WHERE name = ?) OR EXISTS (SELECT 1 FROM {$this->aliasTable()} WHERE alias = ?)",
            [$collection, $collection],
        );
    }

    public function isAlias(string $name): bool
    {
        return (bool) $this->connection->scalar("SELECT COUNT(*) FROM {$this->aliasTable()} WHERE alias = ?", [$name]);
    }

    /**
     * Physical collections only.
     *
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

    /**
     * @return array<string, string>
     */
    public function listAliases(): array
    {
        /** @var array<string, string> $aliases */
        $aliases = $this->connection->table($this->aliasTable())
            ->orderBy('alias')
            ->pluck('collection', 'alias')
            ->all();

        return $aliases;
    }

    public function remove(string $collection): void
    {
        if ($this->isAlias($collection)) {
            throw new VectorQueryException("Collection [{$collection}] is an alias. Delete the alias with deleteAlias(), or delete its collection [{$this->aliasTarget($collection)}].");
        }

        if (! $this->exists($collection)) {
            throw new VectorCollectionNotFoundException("Collection [{$collection}] was not found.");
        }

        $alias = $this->connection->scalar("SELECT alias FROM {$this->aliasTable()} WHERE collection = ? ORDER BY alias LIMIT 1", [$collection]);

        if (is_string($alias)) {
            throw new VectorQueryException("Collection [{$collection}] is the target of alias [{$alias}]. Delete the alias first.");
        }

        $this->connection->delete(
            "DELETE FROM {$this->registryTable} WHERE name = ?",
            [$collection],
        );
    }

    /**
     * Remove the alias and its migration rows (call inside a transaction). Refused while its latest
     * migration is unfinished: the migration still needs the alias.
     */
    public function removeAlias(string $alias): void
    {
        $this->assertValidCollectionName($alias);
        $this->lockAlias($alias);
        $migration = $this->migration($alias);

        if ($migration?->unfinished()) {
            throw new VectorQueryException("Alias [{$alias}] has an unfinished migration to [{$migration->target}] ({$migration->phase}). Run vector:migrate {$alias} again to finish it, then delete the alias.");
        }

        if ($this->connection->delete("DELETE FROM {$this->aliasTable()} WHERE alias = ?", [$alias]) === 0) {
            throw new VectorCollectionNotFoundException("Alias [{$alias}] was not found.");
        }

        $this->connection->delete("DELETE FROM {$this->migrationTable()} WHERE alias = ?", [$alias]);
    }

    public function pointAlias(string $alias, string $collection): void
    {
        $this->assertValidCollectionName($alias);

        if (! $this->connection->scalar("SELECT COUNT(*) FROM {$this->registryTable} WHERE name = ?", [$collection])) {
            throw new VectorCollectionNotFoundException("Collection [{$collection}] was not found.");
        }

        if ($this->connection->update("UPDATE {$this->aliasTable()} SET collection = ?, updated_at = CURRENT_TIMESTAMP WHERE alias = ?", [$collection, $alias]) === 0) {
            throw new VectorCollectionNotFoundException("Alias [{$alias}] was not found.");
        }
    }

    /**
     * Rename collection $name to $physical (its table keeps its storage identifier) and add the alias
     * $name -> $physical. Run inside a transaction.
     */
    public function adopt(string $name, string $physical): void
    {
        $this->assertValidCollectionName($physical);

        if ($this->exists($physical)) {
            throw new VectorQueryException("Collection [{$physical}] already exists.");
        }

        if ($this->isAlias($name) || $this->connection->update("UPDATE {$this->registryTable} SET name = ? WHERE name = ?", [$physical, $name]) === 0) {
            throw new VectorCollectionNotFoundException("Collection [{$name}] was not found.");
        }

        $this->connection->insert("INSERT INTO {$this->aliasTable()} (alias, collection) VALUES (?, ?)", [$name, $physical]);
    }

    /**
     * Resolve a collection or alias in one query. $forWrite locks the alias row (FOR SHARE) until the
     * caller's transaction ends, so a switch waits for the write and later writes see the new target.
     *
     * @return array{name: string, dimensions: int, metric: PgVectorMetric, storage_identifier: string, index_options: array{type?: string, options?: array<string, int>}|null, embedding: array{provider: string, model: string, dimensions: int}|null, keyword: array{languages: array<string, string>, language_field: string}|null}
     */
    public function resolve(string $collection, bool $forWrite = false): array
    {
        $this->assertValidCollectionName($collection);
        $lock = $forWrite ? ' FOR SHARE' : '';

        /** @var object{name: string, dimensions: int|string, metric: string, storage_identifier: string, index_options: string|null, embedding: string|null, keyword: string|null}|null $row */
        $row = $this->connection->selectOne(
            "SELECT name, dimensions, metric, storage_identifier, index_options, embedding, keyword FROM {$this->registryTable}
             WHERE name = COALESCE((SELECT collection FROM {$this->aliasTable()} WHERE alias = ?{$lock}), ?)",
            [$collection, $collection],
        );

        if ($row === null) {
            throw new VectorCollectionNotFoundException("Collection [{$collection}] was not found.");
        }

        return [
            'name' => $row->name,
            'dimensions' => (int) $row->dimensions,
            'metric' => PgVectorMetric::fromString($row->metric),
            'storage_identifier' => $row->storage_identifier,
            'index_options' => $row->index_options !== null ? json_decode($row->index_options, true, flags: JSON_THROW_ON_ERROR) : null,
            'embedding' => $this->decodeEmbedding($row->embedding),
            'keyword' => $this->decodeKeyword($row->keyword),
        ];
    }

    /**
     * JSONB reorders keys; return the language map sorted by key (as CollectionDefinition::keywordSettings()).
     *
     * @return array{languages: array<string, string>, language_field: string}|null
     */
    private function decodeKeyword(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }

        /** @var array{languages: array<string, string>, language_field: string} $keyword */
        $keyword = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $languages = array_combine(array_map(strval(...), array_keys($keyword['languages'])), array_values($keyword['languages']));
        ksort($languages, SORT_STRING);

        return ['languages' => $languages, 'language_field' => (string) $keyword['language_field']];
    }

    /**
     * JSONB reorders keys; return the record in its written order.
     *
     * @return array{provider: string, model: string, dimensions: int}|null
     */
    private function decodeEmbedding(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }

        /** @var array{provider: string, model: string, dimensions: int|string} $embedding */
        $embedding = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return ['provider' => (string) $embedding['provider'], 'model' => (string) $embedding['model'], 'dimensions' => (int) $embedding['dimensions']];
    }

    /**
     * Lock the alias row against writers (FOR UPDATE) until the caller's transaction ends.
     */
    public function lockAlias(string $alias): void
    {
        $this->connection->select("SELECT alias FROM {$this->aliasTable()} WHERE alias = ? FOR UPDATE", [$alias]);
    }

    public function migration(string $alias): ?MigrationState
    {
        /** @var object{alias: string, source: string, target: string, mode: string, phase: string, definition: string, total: int|string, copied: int|string, last_id: string|null}|null $row */
        $row = $this->connection->selectOne(
            "SELECT alias, source, target, mode, phase, definition, total, copied, last_id FROM {$this->migrationTable()} WHERE alias = ? ORDER BY id DESC LIMIT 1",
            [$alias],
        );

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $definition */
        $definition = json_decode($row->definition, true, flags: JSON_THROW_ON_ERROR);

        return new MigrationState($row->alias, $row->source, $row->target, $row->mode, $row->phase, $definition, (int) $row->total, (int) $row->copied, $row->last_id);
    }

    public function saveMigration(MigrationState $state): void
    {
        $this->connection->statement(
            "INSERT INTO {$this->migrationTable()} (alias, source, target, definition, mode, phase, last_id, copied, total)
             VALUES (?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?)
             ON CONFLICT (alias, target) DO UPDATE SET source = EXCLUDED.source, definition = EXCLUDED.definition, mode = EXCLUDED.mode,
                phase = EXCLUDED.phase, last_id = EXCLUDED.last_id, copied = EXCLUDED.copied, total = EXCLUDED.total, updated_at = CURRENT_TIMESTAMP",
            [$state->alias, $state->source, $state->target, json_encode((object) $state->definition, JSON_THROW_ON_ERROR), $state->mode, $state->phase, $state->lastId, $state->copied, $state->total],
        );
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

    private function aliasTarget(string $alias): string
    {
        return (string) $this->connection->scalar("SELECT collection FROM {$this->aliasTable()} WHERE alias = ?", [$alias]);
    }

    private function aliasTable(): string
    {
        return "{$this->registryTable}_aliases";
    }

    private function migrationTable(): string
    {
        return "{$this->registryTable}_migrations";
    }

    private function assertValidCollectionName(string $collection): void
    {
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $collection)) {
            throw new VectorInvalidFilterException("Invalid collection name [{$collection}].");
        }
    }
}
