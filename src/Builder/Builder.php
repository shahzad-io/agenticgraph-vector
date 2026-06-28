<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Builder;

use AgenticGraph\Vector\Contracts\DocumentStoreContract;
use AgenticGraph\Vector\Contracts\SearchStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\FilterOperator;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Tracing\TraceSpan;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\FilterCondition;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;

/**
 * Fluent, immutable query and document builder. Collection context is owned here
 * and injected into store calls — callers use ->collection('articles'), never
 * positional collection arguments on the golden path.
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

    /** @var (callable(int, int): void)|null */
    private $onProgress = null;

    public function __construct(
        private readonly SearchStoreContract $store,
        private string $collection,
        private readonly EmbeddingsManager $embeddings,
    ) {}

    public function collection(string $collection): self
    {
        $clone = clone $this;
        $clone->collection = $collection;

        return $clone;
    }

    public function where(string $field, mixed $value): self
    {
        $clone = clone $this;
        $clone->filterConditions = [
            ...$this->filterConditions,
            new FilterCondition($field, FilterOperator::Equals, $value),
        ];

        return $clone;
    }

    /**
     * @param  list<mixed>  $values
     */
    public function whereIn(string $field, array $values): self
    {
        $clone = clone $this;
        $clone->filterConditions = [
            ...$this->filterConditions,
            new FilterCondition($field, FilterOperator::In, $values),
        ];

        return $clone;
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
        $documentStore = $this->documentStore();

        return TraceSpan::fetch($ids, fn (): SearchResult => $documentStore->fetch($this->collection, $ids));
    }

    /**
     * @param  list<Document>  $documents
     */
    public function insert(array $documents): void
    {
        $documentStore = $this->documentStore();

        TraceSpan::insert($documents, fn () => $documentStore->insert($this->collection, $documents, $this->onProgress));
    }

    /**
     * @param  list<Document>  $documents
     * @param  array<string, mixed>  $globalMetadata
     */
    public function upsert(array $documents, array $globalMetadata = []): void
    {
        $documentStore = $this->documentStore();

        TraceSpan::upsert(
            $documents,
            fn () => $documentStore->upsert($this->collection, $documents, $globalMetadata, $this->onProgress),
        );
    }

    /**
     * @param  list<string|int>|string|int|null  $ids
     * @param  array<string, mixed>  $filter
     */
    public function delete(array|string|int|null $ids = null, array $filter = []): void
    {
        $documentStore = $this->documentStore();

        if ($filter !== [] || ($ids === null && $this->filterConditions !== [])) {
            $conditions = $this->filterConditions;

            if ($filter !== []) {
                $conditions = [
                    ...$conditions,
                    ...NormalizedFilter::fromAssociative($filter)->conditions,
                ];
            }

            if ($conditions === []) {
                throw new VectorQueryException('Delete by filter requires at least one filter condition.');
            }

            $normalized = new NormalizedFilter($conditions);

            $this->assertDeleteByFilterSupported();

            TraceSpan::deleteByFilter(
                $normalized,
                fn () => $documentStore->deleteByFilter($this->collection, $normalized),
            );

            return;
        }

        if ($ids === null) {
            throw new VectorQueryException('Delete requires document id(s) or filter conditions.');
        }

        TraceSpan::delete($ids, fn () => $documentStore->delete($this->collection, $ids));
    }

    public function deleteByFilter(NormalizedFilter $filter): void
    {
        $documentStore = $this->documentStore();

        $this->assertDeleteByFilterSupported();

        TraceSpan::deleteByFilter($filter, fn () => $documentStore->deleteByFilter($this->collection, $filter));
    }

    public function compile(): SearchQuery
    {
        $vector = $this->vector ?? $this->resolveEmbedding($this->similarToText);

        $filter = $this->filterConditions === []
            ? null
            : new NormalizedFilter($this->filterConditions);

        return new SearchQuery(
            collection: $this->collection,
            vector: $vector,
            filter: $filter,
            topK: $this->topK,
            minScore: $this->minScore,
            options: $this->options,
        );
    }

    public function get(): SearchResult
    {
        $this->assertQueryVectorIsSet();

        $query = $this->compile();

        return TraceSpan::search($query, fn (): SearchResult => $this->store->search($query));
    }

    public function store(): SearchStoreContract
    {
        return $this->store;
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

    private function assertDeleteByFilterSupported(): void
    {
        if (! $this->store->capabilities()->supportsDeleteByFilter()) {
            throw new VectorUnsupportedOperationException(
                'Delete by filter is not supported by this store.',
            );
        }
    }

    /**
     * @return list<float>
     */
    private function resolveEmbedding(?string $text): array
    {
        if ($text === null) {
            return [];
        }

        return $this->embeddings->driver()->embed($text);
    }
}
