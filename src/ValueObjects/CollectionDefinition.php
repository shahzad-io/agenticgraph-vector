<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

use AgenticGraph\Vector\Exceptions\VectorQueryException;

final readonly class CollectionDefinition
{
    /** Metadata value => Postgres text search configuration, when keyword search gives no map. */
    public const DEFAULT_LANGUAGES = ['en' => 'english', 'english' => 'english', 'es' => 'spanish', 'spanish' => 'spanish'];

    public const DEFAULT_LANGUAGE_FIELD = 'lang';

    /**
     * @param  string|null  $index  index type (pgvector: hnsw or ivfflat); null uses the store config
     * @param  array<string, mixed>  $indexOptions  pgvector: HNSW m, ef_construction; IVFFlat lists. Validated per store.
     * @param  string|null  $embeddings  embedding provider name (vector.embeddings.providers); null uses the default.
     *                                   The collection records it and embeds documents and queries with it.
     * @param  bool|null  $keyword  keyword search (hybrid()): a new collection has none unless true. In a migration, null keeps the source's setting.
     * @param  array<string, string>|null  $languages  metadata value => text search configuration (pgvector); null is DEFAULT_LANGUAGES. Other values use `simple`.
     * @param  string|null  $languageField  the metadata field holding the language; null is DEFAULT_LANGUAGE_FIELD
     */
    public function __construct(
        public string $name,
        public ?int $dimensions = null,
        public ?string $metric = null,
        public ?string $index = null,
        public array $indexOptions = [],
        public ?string $embeddings = null,
        public ?bool $keyword = null,
        public ?array $languages = null,
        public ?string $languageField = null,
    ) {}

    /**
     * The keyword settings as a store records them, or null without keyword search. The map is sorted by key.
     *
     * @return array{languages: array<string, string>, language_field: string}|null
     */
    public function keywordSettings(): ?array
    {
        if ($this->keyword !== true) {
            return null;
        }

        $languages = $this->languages ?? self::DEFAULT_LANGUAGES;
        $field = $this->languageField ?? self::DEFAULT_LANGUAGE_FIELD;

        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            throw new VectorQueryException("Invalid language field [{$field}].");
        }

        foreach ($languages as $value => $configuration) {
            if (! preg_match('/^[a-zA-Z0-9_-]+$/', (string) $value) || ! preg_match('/^[a-z_][a-z0-9_]*$/', $configuration)) {
                throw new VectorQueryException("Invalid language map entry [{$value}] => [{$configuration}].");
            }
        }

        $languages = array_combine(array_map(strval(...), array_keys($languages)), array_values($languages));
        ksort($languages, SORT_STRING);

        return ['languages' => $languages, 'language_field' => $field];
    }
}
