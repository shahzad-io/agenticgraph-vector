<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

final readonly class CollectionDefinition
{
    /**
     * @param  string|null  $index  index type (pgvector: hnsw or ivfflat); null uses the store config
     * @param  array<string, mixed>  $indexOptions  pgvector: HNSW m, ef_construction; IVFFlat lists. Validated per store.
     */
    public function __construct(
        public string $name,
        public ?int $dimensions = null,
        public ?string $metric = null,
        public ?string $index = null,
        public array $indexOptions = [],
    ) {}
}
