<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

/**
 * Transport-neutral search intent compiled by the Builder and consumed by stores.
 *
 * The `$options` array is reserved STRICTLY for store-specific implementation details
 * (tuning knobs only one store understands). First-class cross-store capabilities MUST
 * become explicit typed fields on this object, NOT `$options` keys.
 *
 * pgvector supported keys (see PgVectorSearchOptions):
 * - hnsw.ef_search (int): HNSW recall vs latency
 * - ivfflat.probes (int): IVFFlat recall vs latency
 */
final readonly class SearchQuery
{
    /**
     * @param  list<float>  $vector
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $collection,
        public array $vector,
        public ?NormalizedFilter $filter = null,
        public int $topK = 10,
        public ?float $minScore = null,
        public array $options = [],
    ) {}
}
