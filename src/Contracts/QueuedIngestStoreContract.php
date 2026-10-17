<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

use AgenticGraph\Vector\ValueObjects\Document;

/**
 * The two steps of a queued ingest (Builder::queue()): each job upserts one batch, and the run finishes once.
 * A store that rebuilds an index after every write (pgvector IVFFlat) skips that per batch and does it once
 * in completeIngest(). A store without this contract gets a plain upsert per batch and nothing on completion.
 */
interface QueuedIngestStoreContract
{
    /**
     * Upsert one batch as upsert() does, without the per-call index rebuild.
     *
     * @param  list<Document>  $documents
     * @param  array<string, mixed>  $globalMetadata
     */
    public function ingestBatch(string $collection, array $documents, array $globalMetadata = []): void;

    /**
     * Run once after every batch of a queued ingest is written (the collection or alias it was given).
     */
    public function completeIngest(string $collection): void;
}
