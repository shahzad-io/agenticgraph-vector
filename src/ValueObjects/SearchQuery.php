<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

/**
 * Transport-neutral search intent compiled by the Builder and consumed by stores.
 *
 * Score contract (the collection's Metric):
 *
 * | Metric        | Score               | Range            |
 * |---------------|---------------------|------------------|
 * | cosine        | cosine similarity   | -1 to 1          |
 * | l2            | 1 / (1 + distance)  | above 0, up to 1 |
 * | inner_product | the dot product     | unbounded        |
 *
 * `$minScore` keeps hits whose vector score is >= the value, in dense and in hybrid search. On l2 a value at or
 * below 0 counts as 0.0001.
 *
 * The `$options` array is reserved STRICTLY for store-specific implementation details
 * (tuning knobs only one store understands). First-class cross-store capabilities MUST
 * become explicit typed fields on this object, NOT `$options` keys.
 *
 * The keys a store accepts, and how it validates them, are documented on each driver.
 *
 * A non-null `$keywords` makes it a hybrid search (capability Keyword): up to max(depth, topK) vector and
 * keyword candidates each, fused by reciprocal rank fusion with `$fusionK`. `$keywordMatch` is how the keywords
 * match: `any` (documents with any of the query's words, ranked by how many and how close) or `websearch`
 * (capability KeywordWebsearch: every word, "quoted phrases", -excluded words, or). `$keywordWeight` multiplies the keyword side's fusion term
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
        public int $depth = 50,
        public int $fusionK = 60,
        public string $keywordMatch = self::KEYWORD_MATCH_ANY,
        public float $keywordWeight = 0.25,
    ) {}
}
