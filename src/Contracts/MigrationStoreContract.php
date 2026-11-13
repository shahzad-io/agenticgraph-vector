<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

use AgenticGraph\Vector\ValueObjects\MigrationState;

/**
 * The steps CollectionMigrator runs (capability Migrations). A migration moves an alias, so this needs aliases.
 */
interface MigrationStoreContract extends AliasStoreContract
{
    /**
     * Turn collection $name into $physical plus an alias $name -> $physical, in one step. The data stays.
     */
    public function adoptCollection(string $name, string $physical): void;

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
     * same step. A short page ends the copy: the phase becomes catching_up. MigrationState::$lastId is the
     * store's opaque cursor.
     */
    public function migrateBatch(MigrationState $state, int $limit): MigrationState;

    /**
     * One pass that applies writes the source took since the copy (new, changed and deleted ids), without
     * a lock. Returns the number of documents that differed.
     */
    public function catchUpMigration(MigrationState $state): int;

    /**
     * Lock the source against writers, run a final pass, verify (count and a sample of ids) and switch the
     * alias, in one step. Above $threshold differing documents nothing switches and it throws. Without
     * $embedInLock, documents that need embedding release the lock and return $state unswitched, so the
     * caller embeds them in another unlocked pass.
     */
    public function finishMigration(MigrationState $state, int $threshold, bool $embedInLock = true): MigrationState;
}
