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
 * - hnsw.iterative_scan (off, relaxed_order, strict_order): keep scanning HNSW until a filtered search has topK hits
 * - hnsw.max_scan_tuples (int): the most rows an iterative scan visits
 * - ivfflat.probes (int): IVFFlat recall vs latency
 *
 * A non-null `$keywords` makes it a hybrid search (capability Keyword): up to max(candidates, topK) vector and
 * keyword candidates each, fused by reciprocal rank fusion with `$fusionK`. `$keywordMatch` is how the keywords
 * match: `any` (documents with any of the query's words, ranked by how many and how close) or `websearch`
 * (every word, "quoted phrases", -excluded words, or). `$keywordWeight` multiplies the keyword side's fusion term
 * (dense weight 1); at 0 the hits are the dense results.
 */
final readonly class SearchQuery
{
    public const KEYWORD_MATCH_ANY = 'any';

    public const KEYWORD_MATCH_WEBSEARCH = 'websearch';

    /** @var list<string> */
    public const KEYWORD_MATCH_MODES = [self::KEYWORD_MATCH_ANY, self::KEYWORD_MATCH_WEBSEARCH];

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
        public ?string $keywords = null,
        public int $candidates = 50,
        public int $fusionK = 60,
        public string $keywordMatch = self::KEYWORD_MATCH_ANY,
        public float $keywordWeight = 0.25,
    ) {}
}
