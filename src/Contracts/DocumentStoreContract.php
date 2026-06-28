<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchResult;

interface DocumentStoreContract
{
    /**
     * @param  list<string|int>  $ids
     */
    public function fetch(array $ids): SearchResult;

    /**
     * @param  list<Document>  $documents
     */
    public function insert(array $documents): void;

    /**
     * @param  list<Document>  $documents
     * @param  array<string, mixed>  $globalMetadata
     */
    public function upsert(array $documents, array $globalMetadata = []): void;

    /**
     * @param  list<string|int>|string|int  $ids
     */
    public function delete(array|string|int $ids): void;

    public function deleteByFilter(NormalizedFilter $filter): void;

    public function batchSize(): int;
}
