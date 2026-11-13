<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

use AgenticGraph\Vector\Enums\FieldType;
use AgenticGraph\Vector\Exceptions\VectorQueryException;

final readonly class CollectionDefinition
{
    /** Metadata value => store-defined language name, when keyword search gives no map. */
    public const DEFAULT_LANGUAGES = ['en' => 'english', 'english' => 'english', 'es' => 'spanish', 'spanish' => 'spanish'];

    public const DEFAULT_LANGUAGE_FIELD = 'lang';

    /**
     * @param  string|null  $metric  cosine, l2 or inner_product (Metric); null uses the store config. A store
     *                               refuses a metric it cannot serve with VectorQueryException.
     * @param  string|null  $index  store-defined index type, validated by the store (see the driver); null uses the store config
     * @param  array<string, mixed>  $indexOptions  store-defined index options, validated by the store (see the driver)
     * @param  string|null  $embeddings  embedding provider name (vector.embeddings.providers); null uses the default.
     *                                   The collection records it and embeds documents and queries with it.
     * @param  bool|null  $keyword  keyword search (hybrid()): a new collection has none unless true. In a migration, null keeps the source's setting.
     * @param  array<string, string>|null  $languages  metadata value => store-defined language name, applied per document
     *                                                 (capability KeywordLanguages); null is DEFAULT_LANGUAGES. Validated by the store.
     * @param  string|null  $languageField  the metadata field holding the language; null is DEFAULT_LANGUAGE_FIELD
     * @param  array<string, FieldType|string>|null  $filterable  declared filterable metadata fields: name => type. A store
     *                                                            validates them on write and filters them alike (FieldType).
     *                                                            Null declares nothing new (a migration keeps the source's).
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
        public ?array $filterable = null,
    ) {}

    /**
     * The refusal when this definition differs from the existing collection's. When the declared fields differ it
     * gives the vector:migrate command that declares them (--filterable replaces the source's fields).
     *
     * @param  array<string, FieldType>  $existing  the existing collection's declared fields
     */
    public function differentDefinition(array $existing): VectorQueryException
    {
        $message = "Collection [{$this->name}] already exists with a different definition. Change it with Vector::migrate() (php artisan vector:migrate).";
        $fields = $this->filterableFields();

        if ($this->filterable !== null && $fields !== $existing) {
            $options = array_map(static fn (string $field, FieldType $type): string => "--filterable={$field}:{$type->value}", array_keys($fields), $fields);
            $message .= " The declared fields differ: php artisan vector:migrate {$this->name} ".implode(' ', $options).' (every field the collection keeps).';
        }

        return new VectorQueryException($message);
    }

    /**
     * The declared fields, validated and sorted by name; [] when none are declared.
     *
     * @return array<string, FieldType>
     */
    public function filterableFields(): array
    {
        $fields = [];

        foreach ($this->filterable ?? [] as $field => $type) {
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $field)) {
                throw new VectorQueryException("Invalid filterable field [{$field}].");
            }

            $fields[(string) $field] = $type instanceof FieldType ? $type : (FieldType::tryFrom($type) ?? throw new VectorQueryException(
                "Filterable field [{$field}] has an unknown type [{$type}]. Use one of: ".implode(', ', array_column(FieldType::cases(), 'value')).'.',
            ));
        }

        ksort($fields, SORT_STRING);

        return $fields;
    }

    /**
     * Throw VectorQueryException when a declared field holds a value of another type. A missing field and null pass.
     * Returns the metadata with an integer on a declared float field cast to float, so it is stored as a float.
     *
     * @param  array<string, FieldType>  $fields
     * @param  array<string, mixed>  $metadata  stored metadata (dates already UTC strings)
     * @return array<string, mixed>
     */
    public static function assertDeclaredTypes(array $fields, array $metadata, string|int $id): array
    {
        foreach ($fields as $field => $type) {
            $value = $metadata[$field] ?? null;

            if ($value !== null && ! $type->accepts($value)) {
                throw new VectorQueryException("Document [{$id}]: metadata field [{$field}] is declared {$type->value} and got ".get_debug_type($value).'.');
            }

            if ($type === FieldType::Float && is_int($value)) {
                $metadata[$field] = (float) $value;
            }
        }

        return $metadata;
    }

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
