<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Exceptions;

use AgenticGraph\Vector\Enums\FieldType;

class VectorUnsupportedOperationException extends VectorException
{
    /**
     * A filter on a field the collection does not declare, on a store that filters declared fields only. Shows the
     * filterable line with the field added, and how to add it to the existing collection.
     *
     * @param  array<string, FieldType|string>  $declared
     */
    public static function undeclaredField(string $field, string $collection, string $store, array $declared, bool $migrations): self
    {
        $entries = [];
        $options = [];

        foreach ($declared + [$field => FieldType::Keyword] as $name => $type) {
            $type = $type instanceof FieldType ? $type : FieldType::from($type);
            $entries[] = "'{$name}' => FieldType::{$type->name}";
            $options[] = "--filterable={$name}:{$type->value}";
        }

        $line = 'filterable: ['.implode(', ', $entries).']';

        return new self(
            "Field [{$field}] is not declared filterable on collection [{$collection}], and store [{$store}] filters declared fields only. "
            ."Declare it in the CollectionDefinition (the type of your values; Keyword shown): {$line}. "
            .($migrations
                ? "Collection [{$collection}] already exists: add the field with php artisan vector:migrate {$collection} --store={$store} ".implode(' ', $options).'.'
                : "Collection [{$collection}] already exists and store [{$store}] has no migrations: create a new collection with the field, copy the documents into it, and switch an alias to it with pointAlias()."),
        );
    }
}
