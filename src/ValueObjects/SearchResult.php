<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

final readonly class SearchResult
{
    /**
     * @param  list<SearchHit>  $hits
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $hits,
        public int $count,
        public array $metadata = [],
    ) {}

    public static function empty(): self
    {
        return new self(hits: [], count: 0);
    }

    public function count(): int
    {
        return $this->count;
    }

    public function first(): ?SearchHit
    {
        return $this->hits[0] ?? null;
    }

    /**
     * @return list<mixed>
     */
    public function pluck(string $field): array
    {
        return array_map(
            static fn (SearchHit $hit): mixed => match ($field) {
                'id' => $hit->id,
                'score' => $hit->score,
                'content' => $hit->content,
                'metadata' => $hit->metadata,
                'vector' => $hit->vector,
                default => $hit->metadata[$field] ?? null,
            },
            $this->hits,
        );
    }

    public function toContext(string $separator = "\n---\n"): string
    {
        return implode($separator, array_map(
            static fn (SearchHit $hit): string => $hit->content,
            $this->hits,
        ));
    }

    /**
     * The hits as plain arrays, for AgenticGraph's $this->llm()->context($result): the core reads this method by
     * name, so neither package imports the other.
     *
     * @return list<array{id: string|int, content: string, metadata: array<string, mixed>, score: float}>
     */
    public function toContextItems(): array
    {
        return array_map(
            static fn (SearchHit $hit): array => ['id' => $hit->id, 'content' => $hit->content, 'metadata' => $hit->metadata, 'score' => $hit->score],
            $this->hits,
        );
    }

    /**
     * @return array{hits: list<array<string, mixed>>, count: int, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'hits' => array_map(
                static fn (SearchHit $hit): array => [
                    'id' => $hit->id,
                    'score' => $hit->score,
                    'content' => $hit->content,
                    'metadata' => $hit->metadata,
                    'vector' => $hit->vector,
                    'scores' => $hit->scores,
                ],
                $this->hits,
            ),
            'count' => $this->count,
            'metadata' => $this->metadata,
        ];
    }

    public function filterByScore(float $min): self
    {
        $filtered = array_values(array_filter(
            $this->hits,
            static fn (SearchHit $hit): bool => $hit->score >= $min,
        ));

        return new self(
            hits: $filtered,
            count: count($filtered),
            metadata: $this->metadata,
        );
    }

    public function averageScore(): ?float
    {
        if ($this->hits === []) {
            return null;
        }

        $total = array_reduce(
            $this->hits,
            static fn (float $carry, SearchHit $hit): float => $carry + $hit->score,
            0.0,
        );

        return $total / count($this->hits);
    }

    public function merge(self $other): self
    {
        $merged = array_merge($this->hits, $other->hits);

        return new self(
            hits: $merged,
            count: count($merged),
            metadata: array_merge($this->metadata, $other->metadata),
        );
    }

    public function unique(string $field): self
    {
        $seen = [];
        $unique = [];

        foreach ($this->hits as $hit) {
            $key = match ($field) {
                'id' => (string) $hit->id,
                'score' => (string) $hit->score,
                'content' => $hit->content,
                default => (string) ($hit->metadata[$field] ?? $hit->id),
            };

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $hit;
        }

        return new self(
            hits: $unique,
            count: count($unique),
            metadata: $this->metadata,
        );
    }
}
