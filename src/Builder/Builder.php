<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Builder;

use AgenticGraph\Vector\Contracts\CollectionStoreContract;
use AgenticGraph\Vector\Contracts\DocumentStoreContract;
use AgenticGraph\Vector\Contracts\SearchStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Rerankers\InstrumentedReranker;
use AgenticGraph\Vector\RerankManager;
use AgenticGraph\Vector\Stores\InstrumentedStore;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\Tracing\VectorTraceContext;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\FilterCondition;
use AgenticGraph\Vector\ValueObjects\IdPage;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\RerankResult;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use AgenticGraph\Vector\ValueObjects\UpdateMetadataResult;

/**
 * Fluent, immutable query and document builder. Collection context is owned here
 * and injected into store calls; callers use ->collection('articles'), never
 * positional collection arguments on the golden path.
 *
 * Every operation checks the store's capabilities first and throws
 * VectorUnsupportedOperationException for a feature the store does not support.
 */
final class Builder
{
    /** @var list<FilterCondition> */
    private array $filterConditions = [];

    private ?string $similarToText = null;

    /** @var list<float>|null */
    private ?array $vector = null;

    private int $topK = 10;

    private ?float $minScore = null;

    /** @var array<string, mixed> */
    private array $options = [];

    private ?string $keywords = null;

    /** @var array{depth: int, k: int, match: string, keyword_weight: float}|null */
    private ?array $hybrid = null;

    /** @var array{reranker: InstrumentedReranker, candidates: int, query: string|null}|null */
    private ?array $rerank = null;

    /** @var (callable(int, int): void)|null */
    private $onProgress = null;

    public function __construct(
        private readonly SearchStoreContract $store,
        private string $collection,
        private readonly EmbeddingsManager $embeddings,
        private readonly ?RerankManager $rerankers = null,
    ) {}

    public function collection(string $collection): self
    {
        $clone = clone $this;
        $clone->collection = $collection;

        return $clone;
    }

    /**
     * where('lang', 'en') or where('year', '>=', 2020). Operators: =, !=, <>, >, >=, <, <=,
     * or a FilterOperator value (eq, ne, gt, gte, lt, lte, contains).
     */
    public function where(string $field, mixed $operatorOrValue, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            return $this->withCondition($field, FilterOperator::Equals, $operatorOrValue);
        }

        $operator = $operatorOrValue instanceof FilterOperator
            ? $operatorOrValue
            : FilterOperator::fromInput((string) $operatorOrValue);

        return $this->withCondition($field, $operator, $value);
    }

    /**
     * @param  list<mixed>  $values
     */
    public function whereIn(string $field, array $values): self
    {
        return $this->withCondition($field, FilterOperator::In, $values);
    }

    /**
     * Documents whose field is none of $values, including documents without the field.
     *
     * @param  list<mixed>  $values
     */
    public function whereNotIn(string $field, array $values): self
    {
        return $this->withCondition($field, FilterOperator::NotIn, $values);
    }

    /**
     * Documents whose field is an array that contains $value.
     */
    public function whereContains(string $field, mixed $value): self
    {
        return $this->withCondition($field, FilterOperator::Contains, $value);
    }

    public function similarTo(string $text): self
    {
        $clone = clone $this;
        $clone->similarToText = $text;
        $clone->vector = null;

        return $clone;
    }

    /**
     * @param  list<float>  $vector
     */
    public function usingVector(array $vector): self
    {
        $clone = clone $this;
        $clone->vector = $vector;
        $clone->similarToText = null;

        return $clone;
    }

    public function take(int $topK): self
    {
        $clone = clone $this;
        $clone->topK = $topK;

        return $clone;
    }

    public function minScore(float $minScore): self
    {
        $clone = clone $this;
        $clone->minScore = $minScore;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function options(array $options): self
    {
        $clone = clone $this;
        $clone->options = $options;

        return $clone;
    }

    /**
     * Hybrid search (capability Keyword): up to max($depth, take()) vector and keyword candidates each, fused
     * by reciprocal rank fusion with $k. Hits come in fused order; their `score` stays the vector similarity, and
     * minScore() applies to it for every hit. The keyword query is the similarTo() text unless keywords() sets one.
     *
     * $match: `any` matches documents with any of the query's words, ranked by how many and how close (for
     * natural-language questions); `websearch` needs every word and reads "quoted phrases", -exclusions and or.
     * Null takes `vector.hybrid.match` (default `any`).
     *
     * $keywordWeight multiplies the keyword side's fusion term (dense weight 1). At 0 the hits are exactly the
     * dense results. Null takes `vector.hybrid.keyword_weight`.
     */
    public function hybrid(int $depth = 50, int $k = 60, ?string $match = null, ?float $keywordWeight = null): self
    {
        if ($depth < 1 || $k < 1) {
            throw new VectorQueryException('hybrid() depth and k must be at least 1.');
        }

        $match ??= (string) config('vector.hybrid.match', SearchQuery::KEYWORD_MATCH_ANY);

        if (! in_array($match, SearchQuery::KEYWORD_MATCH_MODES, true)) {
            throw new VectorQueryException('hybrid() match must be one of: '.implode(', ', SearchQuery::KEYWORD_MATCH_MODES).". Got [{$match}].");
        }

        $keywordWeight ??= (float) config('vector.hybrid.keyword_weight', 0.25);

        if (! is_finite($keywordWeight) || $keywordWeight < 0.0) {
            throw new VectorQueryException("hybrid() keywordWeight must be a finite number of at least 0. Got [{$keywordWeight}].");
        }

        $clone = clone $this;
        $clone->hybrid = ['depth' => $depth, 'k' => $k, 'match' => $match, 'keyword_weight' => $keywordWeight];

        return $clone;
    }

    /**
     * Rerank the search: the store returns max($candidates, take()) hits (dense or hybrid, minScore() already
     * applied to the vector similarity), the reranker named $using (null: `vector.rerank.default`) orders them,
     * and the top take() are returned. `score` stays the vector similarity; the order is `scores['rerank']`.
     * On a reranker error or timeout with `vector.rerank.fail_open` (default true), the store's first take()
     * hits come back and VectorRerankFailed fires.
     *
     * The rerank query is $query, else the similarTo() text, else the hybrid keywords() text; with usingVector() pass one.
     */
    public function rerank(?string $using = null, int $candidates = 30, ?string $query = null): self
    {
        if ($candidates < 1) {
            throw new VectorQueryException("rerank() candidates must be at least 1. Got [{$candidates}].");
        }

        $clone = clone $this;
        $clone->rerank = [
            'reranker' => ($this->rerankers ?? app(RerankManager::class))->reranker($using),
            'candidates' => $candidates,
            'query' => $query,
        ];

        return $clone;
    }

    /**
     * The keyword query of a hybrid() search. In `websearch` match mode it is web search syntax: words (all must
     * match), "quoted phrases", -excluded words, or; in `any` mode operators are ignored. Required with usingVector().
     */
    public function keywords(string $keywords): self
    {
        $clone = clone $this;
        $clone->keywords = $keywords;

        return $clone;
    }

    /**
     * @param  callable(int, int): void  $callback
     */
    public function onProgress(callable $callback): self
    {
        $clone = clone $this;
        $clone->onProgress = $callback;

        return $clone;
    }

    /**
     * @param  list<string|int>  $ids
     */
    public function fetch(array $ids): SearchResult
    {
        return $this->documentStore()->fetch($this->collection, $ids);
    }

    /**
     * @param  list<Document>  $documents
     */
    public function insert(array $documents): void
    {
        $this->documentStore()->insert($this->collection, $documents, $this->onProgress);
    }

    /**
     * Insert or replace by id. An id given more than once keeps its last copy only (progress counts
     * the remaining documents).
     *
     * @param  list<Document>  $documents
     * @param  array<string, mixed>  $globalMetadata
     */
    public function upsert(array $documents, array $globalMetadata = []): void
    {
        $lastCopies = [];

        foreach ($documents as $document) {
            unset($lastCopies[(string) $document->id]);
            $lastCopies[(string) $document->id] = $document;
        }

        $this->documentStore()->upsert($this->collection, array_values($lastCopies), $globalMetadata, $this->onProgress);
    }

    /**
     * Delete by id(s), or by filter (where() conditions and/or $filter). Ids and a filter together throw.
     *
     * @param  list<string|int>|string|int|null  $ids
     * @param  array<string, mixed>  $filter
     */
    public function delete(array|string|int|null $ids = null, array $filter = []): void
    {
        $conditions = $filter === []
            ? $this->filterConditions
            : [...$this->filterConditions, ...NormalizedFilter::fromAssociative($filter)->conditions];

        if ($ids !== null && $conditions !== []) {
            throw new VectorQueryException('Delete takes document id(s) or filter conditions, not both.');
        }

        if ($conditions !== []) {
            $this->deleteByFilter(new NormalizedFilter($conditions));

            return;
        }

        if ($ids === null) {
            throw new VectorQueryException('Delete requires document id(s) or filter conditions.');
        }

        $this->documentStore()->delete($this->collection, $ids);
    }

    public function deleteByFilter(NormalizedFilter $filter): void
    {
        $documentStore = $this->documentStore();

        $this->assertSupported(StoreCapability::DeleteByFilter, 'Delete by filter');
        $this->assertFilterSupported($filter);

        if ($filter->conditions === []) {
            throw new VectorQueryException('Delete by filter requires at least one filter condition.');
        }

        $documentStore->deleteByFilter($this->collection, $filter);
    }

    /**
     * One page of ids matching the where() conditions. Pass the page's nextCursor back for the next one.
     */
    public function listIds(?string $cursor = null, int $limit = 100): IdPage
    {
        $documentStore = $this->documentStore();
        $filter = $this->filter();

        $this->assertSupported(StoreCapability::ListIds, 'Listing ids');
        $this->assertFilterSupported($filter);

        if ($limit < 1) {
            throw new VectorQueryException('listIds() limit must be at least 1.');
        }

        return $documentStore->listIds($this->collection, $filter, $cursor, $limit);
    }

    /**
     * Change metadata without re-embedding: one payload per id. $merge is shallow (top-level keys
     * replaced); false replaces the whole metadata.
     *
     * @param  array<string|int, array<string, mixed>>  $metadataById
     */
    public function updateMetadata(array $metadataById, bool $merge = true): UpdateMetadataResult
    {
        $documentStore = $this->documentStore();

        $this->assertSupported(StoreCapability::UpdateMetadata, 'Updating metadata');

        return $documentStore->updateMetadata($this->collection, $metadataById, $merge);
    }

    /**
     * A similarTo() query is embedded with the provider the collection recorded and targets the collection
     * the name resolved to, so an alias switched in between cannot pair the vector with the other model.
     */
    public function compile(): SearchQuery
    {
        $collection = $this->collection;
        $vector = $this->vector ?? [];

        if ($this->vector === null && $this->similarToText !== null) {
            $provider = null;

            if ($this->store instanceof CollectionStoreContract) {
                $target = $this->store->describeCollection($this->collection);
                $collection = $target['collection'];
                $provider = $target['embedding']['provider'] ?? null;
            }

            $text = $this->similarToText;
            $vector = TraceSpan::embedding(fn (): array => $this->embeddings->driver($provider)->embed($text));
        }

        $topK = $this->rerank === null ? $this->topK : max($this->rerank['candidates'], $this->topK);

        return new SearchQuery(
            collection: $collection,
            vector: $vector,
            filter: $this->filter(),
            topK: $topK,
            minScore: $this->minScore,
            options: $this->options,
            keywords: $this->hybrid === null ? null : ($this->keywords ?? $this->similarToText),
            depth: $this->hybrid['depth'] ?? 50,
            fusionK: $this->hybrid['k'] ?? 60,
            keywordMatch: $this->hybrid['match'] ?? SearchQuery::KEYWORD_MATCH_ANY,
            keywordWeight: $this->hybrid['keyword_weight'] ?? 0.25,
        );
    }

    /**
     * Search. The query text is embedded inside the search span, so its time is the span's
     * `vector.embedding_ms` and an embedding failure fires VectorOperationFailed. A rerank() runs inside
     * the same span (`vector.rerank_*`).
     */
    public function get(): SearchResult
    {
        $this->assertQueryVectorIsSet();
        $this->assertFilterSupported($this->filter());
        $this->assertHybridIsComplete();
        $rerankQuery = $this->rerankQuery();

        $context = new VectorTraceContext(
            store: $this->store instanceof InstrumentedStore ? $this->store->name() : (string) config('vector.default', 'pgvector'),
            collection: $this->collection,
            topK: $this->topK,
            filterCount: count($this->filterConditions),
            searchMode: $this->hybrid !== null ? 'hybrid' : 'dense',
            reranker: $this->rerank !== null ? $this->rerank['reranker']->name() : null,
        );

        return TraceSpan::operation(
            'search',
            function () use ($rerankQuery): SearchResult {
                $result = $this->store->search($this->compile());

                if ($this->rerank === null || $rerankQuery === null) {
                    return $result;
                }

                $reranker = $this->rerank['reranker'];
                $reranked = TraceSpan::rerank(count($result->hits), fn (): RerankResult => $reranker->rerank($rerankQuery, $result->hits, $this->topK));

                return new SearchResult(hits: $reranked->hits, count: count($reranked->hits), metadata: $result->metadata);
            },
            $context,
        );
    }

    public function store(): SearchStoreContract
    {
        return $this->store;
    }

    private function withCondition(string $field, FilterOperator $operator, mixed $value): self
    {
        $clone = clone $this;
        $clone->filterConditions = [
            ...$this->filterConditions,
            new FilterCondition($field, $operator, $value),
        ];

        return $clone;
    }

    private function filter(): ?NormalizedFilter
    {
        return $this->filterConditions === [] ? null : new NormalizedFilter($this->filterConditions);
    }

    private function documentStore(): DocumentStoreContract
    {
        if (! $this->store instanceof DocumentStoreContract) {
            throw new VectorUnsupportedOperationException('This store does not support document operations.');
        }

        return $this->store;
    }

    private function assertQueryVectorIsSet(): void
    {
        if ($this->vector === null && $this->similarToText === null) {
            throw new VectorQueryException(
                'A query is required: call similarTo(text) or usingVector(vector) before get().',
            );
        }
    }

    private function rerankQuery(): ?string
    {
        if ($this->rerank === null) {
            return null;
        }

        return $this->rerank['query'] ?? $this->similarToText ?? $this->keywords ?? throw new VectorQueryException(
            'rerank() with usingVector() needs a query text: pass rerank(query: ...).',
        );
    }

    private function assertHybridIsComplete(): void
    {
        if ($this->hybrid === null) {
            if ($this->keywords !== null) {
                throw new VectorQueryException('keywords() needs hybrid(): call hybrid() to run a keyword search.');
            }

            return;
        }

        $this->assertSupported(StoreCapability::Keyword, 'Hybrid search');

        if ($this->keywords === null && $this->similarToText === null) {
            throw new VectorQueryException('A hybrid search with usingVector() needs keywords(text).');
        }
    }

    private function assertSupported(StoreCapability $capability, string $feature): void
    {
        if (! $this->store->capabilities()->supports($capability)) {
            throw new VectorUnsupportedOperationException("{$feature} is not supported by this store.");
        }
    }

    private function assertFilterSupported(?NormalizedFilter $filter): void
    {
        foreach ($filter === null ? [] : $filter->conditions as $condition) {
            $capability = $condition->operator->capability();

            if ($capability !== null) {
                $this->assertSupported($capability, "The {$condition->operator->value} filter");
            }
        }
    }
}
