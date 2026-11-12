<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Enums;

/**
 * Features a store may support inside its operation groups. The interfaces say which groups exist
 * (document, search); a capability says whether one feature inside them works on this store.
 */
enum StoreCapability: string
{
    case ListIds = 'list_ids';
    case UpdateMetadata = 'update_metadata';
    case DeleteByFilter = 'delete_by_filter';
    case FilterNot = 'filter_not';
    case FilterContains = 'filter_contains';
    case FilterRange = 'filter_range';
    case Keyword = 'keyword';
    case Aliases = 'aliases';
    case QueuedIngest = 'queued_ingest';
    case RebuildIndex = 'rebuild_index';
    /** The `websearch` keyword match: -excluded words, or, "quoted phrases". Keyword alone means the `any` match. */
    case KeywordWebsearch = 'keyword_websearch';
    /** CollectionDefinition::$languages is applied per document. */
    case KeywordLanguages = 'keyword_languages';
    /** A failed chunk of an insert or upsert writes nothing, for every kind of failure. */
    case AtomicBatches = 'atomic_batches';
    /** The MigrationStoreContract methods (Vector::migrate()). */
    case Migrations = 'migrations';
    /** Filters on metadata fields that CollectionDefinition::$filterable does not declare. */
    case UndeclaredFilters = 'undeclared_filters';
}
