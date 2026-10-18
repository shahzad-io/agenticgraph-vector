<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Install the `spanish_unaccent` text search configuration (Spanish stemming after accent folding) on a pgvector
 * store's database. Collections opt in through their language map (`es` to `spanish_unaccent`); the default
 * map is unchanged. Folding is symmetric: documents and queries both fold, so `año` and `ano` match each other.
 */
final class TextSearchInstallCommand extends Command
{
    protected $signature = 'vector:text-search:install
        {--store= : Store name (vector.stores); default store when omitted}';

    protected $description = 'Create the unaccent extension and the spanish_unaccent text search configuration';

    public function handle(Repository $config, DatabaseManager $db): int
    {
        $store = (string) ($this->option('store') ?? $config->get('vector.default'));
        $settings = (array) $config->get("vector.stores.{$store}", []);

        if (($settings['driver'] ?? null) !== 'pgvector') {
            $this->error("Store [{$store}] is not a pgvector store.");

            return self::FAILURE;
        }

        $connection = $db->connection((string) ($settings['connection'] ?? 'pgsql'));

        try {
            $connection->transaction(function () use ($connection): void {
                $connection->statement('CREATE EXTENSION IF NOT EXISTS unaccent');

                if ($connection->scalar("SELECT EXISTS (SELECT 1 FROM pg_catalog.pg_ts_config WHERE cfgname = 'spanish_unaccent' AND pg_catalog.pg_ts_config_is_visible(oid))")) {
                    return;
                }

                $connection->statement('CREATE TEXT SEARCH CONFIGURATION spanish_unaccent (COPY = spanish)');
                $connection->statement('ALTER TEXT SEARCH CONFIGURATION spanish_unaccent ALTER MAPPING FOR hword, hword_part, word WITH unaccent, spanish_stem');
            });
        } catch (Throwable $exception) {
            // Managed Postgres often blocks CREATE EXTENSION for non-superusers.
            $this->error('Could not install spanish_unaccent: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("spanish_unaccent is ready on store [{$store}]. Opt a collection in with the language map es=spanish_unaccent.");

        return self::SUCCESS;
    }
}
