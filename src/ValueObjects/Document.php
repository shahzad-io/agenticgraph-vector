<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

final readonly class Document
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string|int $id,
        public string $content,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function make(string|int $id, string $content, array $metadata = []): self
    {
        return new self($id, $content, $metadata);
    }
}
