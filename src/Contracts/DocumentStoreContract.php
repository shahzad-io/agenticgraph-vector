<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\IdPage;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\UpdateMetadataResult;

/**
 * Store-level document operations. The collection name is supplied by the fluent
 * Builder (Vector::store()->collection('articles')->insert(...)), not by callers
 * of the golden-path DSL.
 *
 * Ids: a store accepts a string or an integer and returns every id as a string. The integer 5 and the
 * string "5" are the same document.
 */
interface DocumentStoreContract
{
    /**
     * @param  list<string|int>  $ids
     */
    public function fetch(string $collection, array $ids): SearchResult;

    /**
     * @param  list<Document>  $documents
     * @param  (callable(int, int): void)|null  $onProgress
     */
    public function insert(string $collection, array $documents, ?callable $onProgress = null): void;

    /**
     * @param  list<Document>  $documents
     * @param  array<string, mixed>  $globalMetadata
     * @param  (callable(int, int): void)|null  $onProgress
     */
    public function upsert(string $collection, array $documents, array $globalMetadata = [], ?callable $onProgress = null): void;

    /**
     * @param  list<string|int>|string|int  $ids
     */
    public function delete(string $collection, array|string|int $ids): void;

    public function deleteByFilter(string $collection, NormalizedFilter $filter): void;

    /**
     * Ids in a store-defined order, $limit at a time. Pass the returned cursor back for the next page; the
     * cursor is opaque. Every id is listed once across the pages.
     */
    public function listIds(string $collection, ?NormalizedFilter $filter = null, ?string $cursor = null, int $limit = 100): IdPage;

    /**
     * Change metadata without touching content or embeddings. $merge is a shallow merge
     * (top-level keys replaced); false replaces the whole metadata.
     *
     * @param  array<string|int, array<string, mixed>>  $metadataById
     */
    public function updateMetadata(string $collection, array $metadataById, bool $merge = true): UpdateMetadataResult;

    public function batchSize(): int;
}
