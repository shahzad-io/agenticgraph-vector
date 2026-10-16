<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

final readonly class UpdateMetadataResult
{
    /**
     * @param  list<string>  $updated  ids whose metadata changed
     * @param  list<string>  $unknown  requested ids the collection does not hold
     */
    public function __construct(
        public array $updated,
        public array $unknown,
    ) {}
}
