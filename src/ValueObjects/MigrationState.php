<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

/**
 * One migration of an alias from its source collection to a new target collection.
 *
 * Phases: planned (dry run, never stored), copying, catching_up, switched, rolled_back, dropped, failed.
 * Mode: copy (vectors copied as they are) or reembed (content embedded with the target's provider).
 */
final readonly class MigrationState
{
    /**
     * @param  array<string, mixed>  $definition  the requested definition without its name
     */
    public function __construct(
        public string $alias,
        public string $source,
        public string $target,
        public string $mode,
        public string $phase,
        public array $definition,
        public int $total = 0,
        public int $copied = 0,
        public ?string $lastId = null,
    ) {}

    public function unfinished(): bool
    {
        return in_array($this->phase, ['copying', 'catching_up'], true);
    }

    public function withPhase(string $phase): self
    {
        return new self($this->alias, $this->source, $this->target, $this->mode, $phase, $this->definition, $this->total, $this->copied, $this->lastId);
    }

    public function withProgress(int $copied, ?string $lastId): self
    {
        return new self($this->alias, $this->source, $this->target, $this->mode, $this->phase, $this->definition, $this->total, $copied, $lastId);
    }

    /**
     * Embedding requests the copy needs: one per batch when re-embedding, none when copying vectors.
     */
    public function estimatedEmbeddingCalls(int $batchSize): int
    {
        return $this->mode === 'reembed' ? (int) ceil(max(0, $this->total - $this->copied) / max(1, $batchSize)) : 0;
    }

    /**
     * Keyword keys are present only with keyword search, so a migration recorded without them resumes unchanged.
     *
     * @return array<string, mixed>
     */
    public static function definitionOf(CollectionDefinition $definition): array
    {
        $keyword = $definition->keywordSettings();

        return [
            'dimensions' => $definition->dimensions,
            'metric' => $definition->metric,
            'index' => $definition->index,
            'index_options' => $definition->indexOptions,
            'embeddings' => $definition->embeddings,
            ...($keyword === null ? [] : ['keyword' => true, 'languages' => $keyword['languages'], 'language_field' => $keyword['language_field']]),
        ];
    }

    public function collectionDefinition(): CollectionDefinition
    {
        /** @var array<string, mixed> $indexOptions */
        $indexOptions = $this->definition['index_options'] ?? [];
        /** @var array<string, string>|null $languages */
        $languages = $this->definition['languages'] ?? null;

        return new CollectionDefinition(
            name: $this->target,
            dimensions: isset($this->definition['dimensions']) ? (int) $this->definition['dimensions'] : null,
            metric: isset($this->definition['metric']) ? (string) $this->definition['metric'] : null,
            index: isset($this->definition['index']) ? (string) $this->definition['index'] : null,
            indexOptions: $indexOptions,
            embeddings: isset($this->definition['embeddings']) ? (string) $this->definition['embeddings'] : null,
            keyword: isset($this->definition['keyword']) ? (bool) $this->definition['keyword'] : null,
            languages: $languages,
            languageField: isset($this->definition['language_field']) ? (string) $this->definition['language_field'] : null,
        );
    }
}
