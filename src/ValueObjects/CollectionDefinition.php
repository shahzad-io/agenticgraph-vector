<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

final readonly class CollectionDefinition
{
    public function __construct(
        public string $name,
        public ?int $dimensions = null,
        public string $metric = 'cosine',
    ) {}
}
