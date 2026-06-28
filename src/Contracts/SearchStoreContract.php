<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;

interface SearchStoreContract
{
    public function search(SearchQuery $query): SearchResult;

    public function capabilities(): StoreCapabilities;
}
