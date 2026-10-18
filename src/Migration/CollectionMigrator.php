<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Migration;

use AgenticGraph\Vector\Contracts\AliasStoreContract;
use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Events\VectorMigrationProgressed;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\MigrationState;
use InvalidArgumentException;
use Throwable;

/**
 * Moves an alias to a new collection: adopt a plain collection, create the target, copy or re-embed in
 * batches, catch up writes, then a locked final pass, verify and switch. Resumable from the store's
 * migration record; the old collection stays for rollback until dropOld().
 */
final class CollectionMigrator
{
    public function __construct(
        private readonly AliasStoreContract&VectorStoreContract $store,
        private readonly string $storeName,
        private readonly EmbeddingsManager $embeddings,
    ) {}

    /**
     * @param  string|null  $mode  copy or reembed to override the choice; required when the source has no recorded embeddings
     */
    public function migrate(string $alias, CollectionDefinition $definition, bool $dryRun = false, ?string $mode = null, ?int $batchSize = null): MigrationState
    {
        if ($mode !== null && ! in_array($mode, ['copy', 'reembed'], true)) {
            throw new InvalidArgumentException("Migration mode [{$mode}] must be copy or reembed.");
        }

        $batch = max(1, $batchSize ?? $this->store->batchSize());
        $definition = $this->inherit($alias, $definition);
        $wanted = MigrationState::definitionOf($definition);
        $state = $this->store->migration($alias);

        if ($state !== null && $state->unfinished()) {
            if (self::canonical($state->definition) !== self::canonical($wanted)) {
                throw new VectorQueryException(
                    "Migration of [{$alias}] to [{$state->target}] is unfinished with a different definition. Run it again with that definition first.",
                );
            }

            if ($dryRun) {
                return $state;
            }
        } else {
            $state = $this->plan($alias, $definition, $wanted, $mode);

            if ($dryRun) {
                return $state;
            }

            // A source that does not exist yet is the adopted name of a plain collection.
            if (! in_array($state->source, $this->store->listCollections(), true)) {
                $this->store->adoptCollection($alias, $state->source);
            }

            $state = $state->withPhase('copying');
            $this->store->saveMigration($state);
        }

        if ($state->phase === 'copying') {
            try {
                $this->store->createCollection($state->collectionDefinition());
            } catch (Throwable $exception) {
                $this->store->saveMigration($state->withPhase('failed'));

                throw $exception;
            }
        }

        $this->progress($state);

        while ($state->phase === 'copying') {
            $state = $this->store->migrateBatch($state, $batch);
            $this->progress($state);
        }

        $threshold = max(0, (int) config('vector.migration.catch_up_threshold', 500));
        $passes = max(1, (int) config('vector.migration.catch_up_max_passes', 10));

        // Writes that outpace the passes make the final pass abort above the threshold; a rerun continues.
        // A final pass that finds rows to embed releases the lock for another unlocked pass; only the last
        // pass embeds inside the lock.
        for ($pass = 1; ; $pass++) {
            $last = $pass >= $passes;

            if ($this->store->catchUpMigration($state) > $threshold && ! $last) {
                continue;
            }

            $finished = $this->store->finishMigration($state, $threshold, embedInLock: $last);

            if ($finished->phase === 'switched' || $last) {
                break;
            }
        }

        $state = $finished;
        $this->progress($state);

        return $state;
    }

    /**
     * Point the alias back at the collection its last switched migration came from.
     */
    public function rollback(string $alias): MigrationState
    {
        $state = $this->switchedMigration($alias, 'roll back');

        if (! in_array($state->source, $this->store->listCollections(), true)) {
            throw new VectorQueryException("Cannot roll back [{$alias}]: its previous collection [{$state->source}] no longer exists.");
        }

        $this->store->pointAlias($alias, $state->source);

        return $this->finish($state->withPhase('rolled_back'));
    }

    /**
     * Delete the collection the alias's last switched migration came from.
     */
    public function dropOld(string $alias): MigrationState
    {
        $state = $this->switchedMigration($alias, 'drop');
        $this->store->deleteCollection($state->source);

        return $this->finish($state->withPhase('dropped'));
    }

    /**
     * Fill what the definition leaves out from the source collection. A new provider brings its own width,
     * and a new index type its own options; given index options are merged over the source's. Keyword search,
     * its language map and field are kept unless given.
     */
    private function inherit(string $alias, CollectionDefinition $definition): CollectionDefinition
    {
        $source = $this->store->describeCollection($alias);
        $sourceProvider = $source['embedding']['provider'] ?? null;
        $embeddings = $definition->embeddings ?? $sourceProvider;
        $index = $definition->index ?? $source['index']['type'] ?? null;
        $sameIndex = $index === ($source['index']['type'] ?? null);

        return new CollectionDefinition(
            name: $alias,
            dimensions: $definition->dimensions ?? ($embeddings === $sourceProvider ? $source['dimensions'] : $this->embeddings->using($embeddings)->dimensions()),
            metric: $definition->metric ?? $source['metric'],
            index: $index,
            indexOptions: $sameIndex ? array_merge($source['index']['options'] ?? [], $definition->indexOptions) : $definition->indexOptions,
            embeddings: $embeddings,
            keyword: $definition->keyword ?? (($source['keyword'] ?? null) !== null),
            languages: $definition->languages ?? $source['keyword']['languages'] ?? null,
            languageField: $definition->languageField ?? $source['keyword']['language_field'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $wanted
     */
    private function plan(string $alias, CollectionDefinition $definition, array $wanted, ?string $mode): MigrationState
    {
        $source = $this->store->describeCollection($alias);
        $adopt = ! array_key_exists($alias, $this->store->listAliases());

        $version = 0;
        foreach ($this->store->listCollections() as $name) {
            if (preg_match('/^'.preg_quote($alias, '/').'__v(\d+)$/', $name, $match)) {
                $version = max($version, (int) $match[1]);
            }
        }

        $sourceName = $adopt ? $alias.'__v'.(++$version) : $source['collection'];
        $dimensions = (int) $definition->dimensions;
        $target = $this->embeddings->record($definition->embeddings, $dimensions);
        $mode = $this->mode($alias, $source, $target, $dimensions, $mode);

        if ($mode === 'reembed' && ($width = $this->embeddings->using($definition->embeddings)->dimensions()) !== $dimensions) {
            throw new VectorQueryException(
                "Cannot re-embed [{$alias}] into {$dimensions} dimensions: provider [".($definition->embeddings ?? $this->embeddings->getDefaultDriver())."] embeds at {$width}. Name a provider with that width (--embeddings).",
            );
        }

        return new MigrationState(
            alias: $alias,
            source: $sourceName,
            target: $alias.'__v'.($version + 1),
            mode: $mode,
            phase: 'planned',
            definition: $wanted,
            total: $this->store->countDocuments($alias),
        );
    }

    /**
     * Copy when provider, model and width match; re-embed otherwise. Unknown source embeddings need $forced.
     *
     * @param  array{collection: string, dimensions: int, metric: string, index: array{type: string, options: array<string, int>}|null, embedding: array{provider: string, model: string, dimensions: int}|null}  $source
     * @param  array{provider: string, model: string, dimensions: int}|null  $target
     */
    private function mode(string $alias, array $source, ?array $target, int $dimensions, ?string $forced): string
    {
        $recorded = $source['embedding'];

        if ($forced === 'reembed') {
            return 'reembed';
        }

        if ($forced === 'copy') {
            if ($source['dimensions'] !== $dimensions) {
                throw new VectorQueryException("Cannot copy [{$alias}]: its vectors have {$source['dimensions']} dimensions, the new definition {$dimensions}. Re-embed instead.");
            }

            if ($recorded !== null && $target !== null && $recorded['model'] !== $target['model']) {
                throw new VectorQueryException("Cannot copy [{$alias}]: its vectors come from [{$recorded['model']}], the new definition embeds with [{$target['model']}]. Re-embed instead.");
            }

            return 'copy';
        }

        if ($recorded === null || $target === null) {
            throw new VectorQueryException(
                "Cannot tell whether [{$alias}] can be copied: ".($recorded === null ? "[{$source['collection']}] has no recorded embedding model (created before B5, or without a provider)" : 'the new definition has no embedding model')
                .'. Choose: copy the vectors as they are (mode copy, --copy) or re-embed (mode reembed, --reembed).',
            );
        }

        return $recorded['provider'] === $target['provider'] && $recorded['model'] === $target['model'] && $source['dimensions'] === $dimensions
            ? 'copy'
            : 'reembed';
    }

    private function switchedMigration(string $alias, string $action): MigrationState
    {
        $state = $this->store->migration($alias);

        if ($state === null || $state->phase !== 'switched') {
            throw new VectorQueryException("Cannot {$action} for [{$alias}]: its last migration is ".($state === null ? 'missing' : "{$state->phase}").', not switched.');
        }

        return $state;
    }

    private function finish(MigrationState $state): MigrationState
    {
        $this->store->saveMigration($state);
        $this->progress($state);

        return $state;
    }

    private function progress(MigrationState $state): void
    {
        VectorMigrationProgressed::record($this->storeName, $state->alias, $state->source, $state->target, $state->phase, $state->copied, $state->total);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function canonical(array $definition): string
    {
        foreach (['index_options', 'languages'] as $key) {
            if (isset($definition[$key])) {
                $sorted = (array) $definition[$key];
                ksort($sorted);
                $definition[$key] = $sorted;
            }
        }

        $definition['index_options'] ??= [];
        ksort($definition);

        return json_encode($definition, JSON_THROW_ON_ERROR);
    }
}
