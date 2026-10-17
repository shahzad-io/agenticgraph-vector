<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

/**
 * Index maintenance (capability RebuildIndex).
 */
interface IndexStoreContract
{
    /**
     * Build the collection's (or alias's) embedding index again from the rows it holds now, without blocking
     * searches or writes. Two rebuilds of one collection do not run at once: the second throws VectorQueryException.
     */
    public function rebuildIndex(string $collection): void;
}
