<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

use AgenticGraph\Vector\ValueObjects\MigrationState;

/**
 * Aliases (capability Aliases) and the steps CollectionMigrator runs. A store resolves an alias to its
 * collection in every operation; aliases and collections share one namespace.
 */
interface AliasStoreContract
{
    /**
     * @return array<string, string> alias => collection, sorted by alias
     */
    public function listAliases(): array;

    /**
     * Remove the alias and its migration history; its collection stays. Throws VectorQueryException while the
     * alias's latest migration is unfinished (copying or catching_up).
     */
    public function deleteAlias(string $alias): void;

    /**
     * Point an existing alias at another collection.
     */
    public function pointAlias(string $alias, string $collection): void;

    /**
     * Turn collection $name into $physical plus an alias $name -> $physical, in one step. The data stays.
     */
    public function adoptCollection(string $name, string $physical): void;

    public function countDocuments(string $collection): int;

    /**
     * The alias's latest migration, or null.
     */
    public function migration(string $alias): ?MigrationState;

    /**
     * Store the migration (keyed by alias and target).
     */
    public function saveMigration(MigrationState $state): void;

    /**
     * Copy or re-embed the next $limit documents after the cursor and store the advanced cursor in the
     * same step. A short page ends the copy: the phase becomes catching_up (IVFFlat targets rebuild).
     */
    public function migrateBatch(MigrationState $state, int $limit): MigrationState;

    /**
     * One pass that applies writes the source took since the copy (new, changed and deleted ids), without
     * a lock. Returns the number of documents that differed.
     */
    public function catchUpMigration(MigrationState $state): int;

    /**
     * Lock the source against writers, run a final pass, verify (count and a sample of ids) and switch the
     * alias, in one step. Above $threshold differing documents nothing switches and it throws.
     */
    public function finishMigration(MigrationState $state, int $threshold): MigrationState;
}
