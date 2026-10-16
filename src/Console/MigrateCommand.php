<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Console;

use AgenticGraph\Vector\Events\VectorMigrationProgressed;
use AgenticGraph\Vector\Exceptions\VectorException;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;

final class MigrateCommand extends Command
{
    protected $signature = 'vector:migrate {alias : Collection or alias to migrate}
        {--store= : Store name (vector.stores); default store when omitted}
        {--dimensions= : Width of the new collection; the source width when omitted (a new provider brings its own)}
        {--metric= : cosine, l2 or inner_product; the source metric when omitted}
        {--index= : hnsw or ivfflat; the source index when omitted}
        {--index-option=* : Index option as key=value, for example m=16; merged over the source options for the same index type}
        {--embeddings= : Embedding provider name (vector.embeddings.providers); the source provider when omitted}
        {--keyword : Add keyword search (hybrid()); the source setting when neither --keyword nor --no-keyword is given}
        {--no-keyword : Remove keyword search}
        {--language=* : Language map entry as value=configuration, for example es=spanish; replaces the source map}
        {--language-field= : Metadata field holding the language; the source field (or lang) when omitted}
        {--batch= : Documents per batch; the store batch size when omitted}
        {--copy : Copy vectors as they are (needed when the source has no recorded embedding model)}
        {--reembed : Re-embed every document with the new provider}
        {--dry-run : Print the plan, write nothing}
        {--drop-old : Delete the collection the last migration came from}
        {--rollback : Point the alias back at the collection the last migration came from}';

    protected $description = 'Move a vector collection to a new definition behind an alias, with zero downtime';

    /** Progress is printed only while this command runs a migration; the listener is registered once per instance. */
    private bool $printing = false;

    private bool $listening = false;

    public function handle(VectorManager $vector, Dispatcher $events): int
    {
        $alias = (string) $this->argument('alias');
        $store = $this->option('store') !== null ? (string) $this->option('store') : null;

        try {
            if ($this->option('rollback')) {
                $state = $vector->rollbackMigration($alias, $store);
                $this->info("[{$alias}] points at [{$state->source}] again.");

                return self::SUCCESS;
            }

            if ($this->option('drop-old')) {
                $state = $vector->dropOldCollection($alias, $store);
                $this->info("Dropped [{$state->source}]. [{$alias}] stays on [{$state->target}].");

                return self::SUCCESS;
            }

            if ($this->option('copy') && $this->option('reembed')) {
                throw new InvalidArgumentException('Pass --copy or --reembed, not both.');
            }

            if ($this->option('keyword') && $this->option('no-keyword')) {
                throw new InvalidArgumentException('Pass --keyword or --no-keyword, not both.');
            }

            $batch = $this->option('batch') !== null ? (int) $this->option('batch') : null;
            $dryRun = (bool) $this->option('dry-run');

            if (! $this->listening) {
                $events->listen(VectorMigrationProgressed::class, function (VectorMigrationProgressed $event): void {
                    if ($this->printing) {
                        $this->line("{$event->phase}: {$event->copied}/{$event->total} ({$event->source} -> {$event->target})");
                    }
                });
                $this->listening = true;
            }

            $this->printing = ! $dryRun;

            try {
                $state = $vector->migrate(
                    $alias,
                    $this->definition($alias),
                    store: $store,
                    dryRun: $dryRun,
                    mode: $this->option('copy') ? 'copy' : ($this->option('reembed') ? 'reembed' : null),
                    batchSize: $batch,
                );
            } finally {
                $this->printing = false;
            }
        } catch (VectorException|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->table(['source', 'target', 'documents', 'mode', 'embedding calls', 'phase'], [[
                $state->source,
                $state->target,
                $state->total,
                $state->mode,
                $state->estimatedEmbeddingCalls($batch ?? $vector->store($store)->batchSize()),
                $state->phase,
            ]]);
            $this->table(['dimensions', 'metric', 'index', 'index options', 'embeddings', 'keyword'], [[
                $state->definition['dimensions'],
                $state->definition['metric'],
                $state->definition['index'] ?? 'store default',
                http_build_query((array) $state->definition['index_options'], arg_separator: ', ') ?: '-',
                $state->definition['embeddings'] ?? 'default (unrecorded)',
                isset($state->definition['keyword'])
                    ? (string) $state->definition['language_field'].': '.(http_build_query((array) $state->definition['languages'], arg_separator: ', ') ?: '-').', else simple'
                    : 'no',
            ]]);

            return self::SUCCESS;
        }

        $this->info("[{$alias}] now points at [{$state->target}]. The old collection [{$state->source}] is kept: --rollback to go back, --drop-old to delete it.");

        return self::SUCCESS;
    }

    private function definition(string $alias): CollectionDefinition
    {
        $options = [];

        foreach ((array) $this->option('index-option') as $option) {
            [$key, $value] = array_pad(explode('=', (string) $option, 2), 2, null);

            if ($key === '' || $value === null) {
                throw new InvalidArgumentException("Index option [{$option}] must be key=value.");
            }

            $options[$key] = is_numeric($value) ? (int) $value : $value;
        }

        $languages = null;

        foreach ((array) $this->option('language') as $entry) {
            [$value, $configuration] = array_pad(explode('=', (string) $entry, 2), 2, null);

            if ($value === '' || $configuration === null) {
                throw new InvalidArgumentException("Language map entry [{$entry}] must be value=configuration.");
            }

            $languages[$value] = $configuration;
        }

        return new CollectionDefinition(
            name: $alias,
            dimensions: $this->option('dimensions') !== null ? (int) $this->option('dimensions') : null,
            metric: $this->option('metric') !== null ? (string) $this->option('metric') : null,
            index: $this->option('index') !== null ? (string) $this->option('index') : null,
            indexOptions: $options,
            embeddings: $this->option('embeddings') !== null ? (string) $this->option('embeddings') : null,
            keyword: $this->option('keyword') ? true : ($this->option('no-keyword') ? false : null),
            languages: $languages,
            languageField: $this->option('language-field') !== null ? (string) $this->option('language-field') : null,
        );
    }
}
