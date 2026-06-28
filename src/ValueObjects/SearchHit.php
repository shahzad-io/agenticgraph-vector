<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

final readonly class SearchHit
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<float>|null  $vector
     */
    public function __construct(
        public string|int $id,
        public float $score,
        public string $content,
        public array $metadata = [],
        public ?array $vector = null,
    ) {}
}
