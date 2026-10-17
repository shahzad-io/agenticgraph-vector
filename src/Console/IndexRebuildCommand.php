<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Console;

use AgenticGraph\Vector\Exceptions\VectorException;
use AgenticGraph\Vector\VectorManager;
use Illuminate\Console\Command;

/**
 * Rebuild a collection's embedding index from the rows it holds now (rebuildIndex()). Searches and writes go on
 * meanwhile; a second rebuild of the same collection refuses while the first runs. Run it after the first bulk load of
 * an IVFFlat collection, and again when the data has changed a lot.
 */
final class IndexRebuildCommand extends Command
{
    protected $signature = 'vector:index:rebuild {collection : Collection or alias}
        {--store= : Store name (vector.stores); default store when omitted}';

    protected $description = 'Rebuild the embedding index of a vector collection without blocking searches or writes';

    public function handle(VectorManager $vector): int
    {
        $collection = (string) $this->argument('collection');
        $started = hrtime(true);

        try {
            $vector->rebuildIndex($collection, $this->option('store') !== null ? (string) $this->option('store') : null);
        } catch (VectorException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Rebuilt the index of [%s] in %.1f s.', $collection, (hrtime(true) - $started) / 1e9));

        return self::SUCCESS;
    }
}
