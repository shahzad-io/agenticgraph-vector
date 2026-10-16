<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

use AgenticGraph\Vector\Builder\Builder;

interface VectorStoreContract extends CollectionStoreContract, DocumentStoreContract, SearchStoreContract
{
    /**
     * The fluent builder for one collection of this store.
     */
    public function collection(string $name): Builder;
}
