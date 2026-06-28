<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

final readonly class Document
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<float>|null  $vector
     */
    public function __construct(
        public string|int $id,
        public string $content,
        public array $metadata = [],
        public ?array $vector = null,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<float>|null  $vector
     */
    public static function make(
        string|int $id,
        string $content,
        array $metadata = [],
        ?array $vector = null,
    ): self {
        return new self($id, $content, $metadata, $vector);
    }
}
