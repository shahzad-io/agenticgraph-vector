<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

final readonly class SearchHit
{
    /**
     * `score` is the vector similarity in every mode. In hybrid mode, hits are ordered by `scores['fused']`,
     * not by `score`; `scores['keyword']` is null for a hit that did not match the keywords.
     *
     * @param  array<string, mixed>  $metadata
     * @param  list<float>|null  $vector
     * @param  array{vector: float, keyword: float|null, fused: float}|null  $scores  hybrid mode only
     */
    public function __construct(
        public string|int $id,
        public float $score,
        public string $content,
        public array $metadata = [],
        public ?array $vector = null,
        public ?array $scores = null,
    ) {}
}
