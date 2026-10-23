<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\PgVector;

use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;

/**
 * The embedding index of one pgvector collection: type and effective options (defaults filled in).
 *
 * The requested options are kept in the registry (`index_options`). The real index in Postgres is
 * the check: its type and HNSW options are read back from the index definition. IVFFlat `lists` is
 * clamped to the row count when the index is built (create, migration copy, rebuildIndex()), so its requested
 * value comes from the registry.
 */
final readonly class PgVectorIndex
{
    /** pgvector's own HNSW defaults. */
    private const HNSW_DEFAULTS = ['m' => 16, 'ef_construction' => 64];

    /**
     * @param  array<string, int>  $options
     */
    public function __construct(
        public string $type,
        public array $options,
    ) {}

    public static function fromDefinition(CollectionDefinition $definition, string $configuredType, int $configuredLists): self
    {
        $type = $definition->index ?? $configuredType;
        $options = $definition->indexOptions;

        $allowed = match ($type) {
            'hnsw' => ['m', 'ef_construction'],
            'ivfflat' => ['lists'],
            default => throw new VectorQueryException("Index [{$type}] is not supported by pgvector. Use hnsw or ivfflat."),
        };

        foreach ($options as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw new VectorQueryException("Index option [{$key}] is not valid for {$type}. Valid options: ".implode(', ', $allowed).'.');
            }

            if (! is_int($value)) {
                throw new VectorQueryException("Index option [{$key}] must be an integer.");
            }
        }

        if ($type === 'ivfflat') {
            $lists = $options['lists'] ?? $configuredLists;
            self::assertBetween('lists', $lists, 1, 32768);

            return new self('ivfflat', ['lists' => $lists]);
        }

        $effective = array_merge(self::HNSW_DEFAULTS, $options);
        self::assertBetween('m', $effective['m'], 2, 100);
        self::assertBetween('ef_construction', $effective['ef_construction'], 4, 1000);

        if ($effective['ef_construction'] < 2 * $effective['m']) {
            throw new VectorQueryException('Index option [ef_construction] must be at least twice [m].');
        }

        return new self('hnsw', $effective);
    }

    /**
     * @param  string|null  $indexDefinition  pg_get_indexdef() of the embedding index
     * @param  array{type?: string, options?: array<string, int>}|null  $registered  the registry's index_options (null in an older registry)
     */
    public static function fromExisting(?string $indexDefinition, ?array $registered, int $configuredLists): ?self
    {
        if ($indexDefinition === null || ! preg_match('/USING (\w+)/', $indexDefinition, $type)) {
            return null;
        }

        if ($type[1] === 'ivfflat') {
            return new self('ivfflat', ['lists' => (int) ($registered['options']['lists'] ?? $configuredLists)]);
        }

        preg_match_all("/(\w+)='?(\d+)'?/", (string) strstr($indexDefinition, 'WITH ('), $pairs, PREG_SET_ORDER);
        $options = self::HNSW_DEFAULTS;

        foreach ($pairs as [, $key, $value]) {
            $options[$key] = (int) $value;
        }

        return new self($type[1], $options);
    }

    /**
     * The index the registry recorded, for a collection whose index a killed rebuild dropped before its rename.
     *
     * @param  array{type?: string, options?: array<string, int>}|null  $registered
     */
    public static function fromRegistered(?array $registered, string $configuredType, int $configuredLists): self
    {
        $type = $registered['type'] ?? $configuredType;

        return $type === 'ivfflat'
            ? new self('ivfflat', ['lists' => (int) ($registered['options']['lists'] ?? $configuredLists)])
            : new self($type, array_merge(self::HNSW_DEFAULTS, $registered['options'] ?? []));
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->options == $other->options;
    }

    public function createSql(string $storage, string $ops, int $rowCount = 0, bool $concurrently = false, ?string $name = null): string
    {
        $with = $this->type === 'ivfflat'
            ? 'lists = '.max(1, min($this->options['lists'], $rowCount))
            : "m = {$this->options['m']}, ef_construction = {$this->options['ef_construction']}";
        $name ??= "{$storage}_embedding_idx";

        return 'CREATE INDEX '.($concurrently ? 'CONCURRENTLY ' : '')."{$name} ON {$storage} USING {$this->type} (embedding {$ops}) WITH ({$with})";
    }

    /**
     * What the registry stores in `index_options`.
     *
     * @return array{type: string, options: array<string, int>}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'options' => $this->options];
    }

    private static function assertBetween(string $key, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new VectorQueryException("Index option [{$key}] must be between {$min} and {$max}.");
        }
    }
}
