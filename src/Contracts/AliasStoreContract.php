<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

/**
 * Aliases (capability Aliases). A store resolves an alias to its collection in every operation; aliases and
 * collections share one namespace. Migrations are MigrationStoreContract (capability Migrations).
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

    public function countDocuments(string $collection): int;
}
