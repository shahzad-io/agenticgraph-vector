<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchResult;

/**
 * Store-level document operations. The collection name is supplied by the fluent
 * Builder (Vector::store()->collection('articles')->insert(...)), not by callers
 * of the golden-path DSL.
 */
interface DocumentStoreContract
{
    /**
     * @param  list<string|int>  $ids
     */
    public function fetch(string $collection, array $ids): SearchResult;

    /**
     * @param  list<Document>  $documents
     */
    public function insert(string $collection, array $documents): void;

    /**
     * @param  list<Document>  $documents
     * @param  array<string, mixed>  $globalMetadata
     */
    public function upsert(string $collection, array $documents, array $globalMetadata = []): void;

    /**
     * @param  list<string|int>|string|int  $ids
     */
    public function delete(string $collection, array|string|int $ids): void;

    public function deleteByFilter(string $collection, NormalizedFilter $filter): void;

    public function batchSize(): int;
}
