<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Console;

use AgenticGraph\Vector\Ingest\QueuedIngest;
use Illuminate\Console\Command;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Bus;

/**
 * Retry a queued ingest's failed batches: the failed jobs of its job batch go back on their queue (queue:retry),
 * with the documents they carried. Written batches do not run again. Failed jobs are recorded by queue workers,
 * so a run that ran inline on the sync connection has none to retry: run the upsert again.
 */
final class IngestResumeCommand extends Command
{
    protected $signature = 'vector:ingest:resume {run : The ingest run id (vector_ingest_runs.id)}';

    protected $description = 'Retry the failed batches of a queued vector ingest';

    public function handle(FailedJobProviderInterface $failer): int
    {
        $runId = (string) $this->argument('run');
        $run = QueuedIngest::find($runId);

        if ($run === null) {
            $this->error("No ingest run [{$runId}].");

            return self::FAILURE;
        }

        if ($run->status === 'completed') {
            $this->info("Ingest run [{$runId}] is already completed.");

            return self::SUCCESS;
        }

        $batch = $run->batchId !== null ? Bus::findBatch($run->batchId) : null;
        $failedJobIds = $batch->failedJobIds ?? [];

        if ($failedJobIds === []) {
            $this->error($run->batchId === null
                ? "Ingest run [{$runId}] ran inline on the sync connection: there are no failed jobs to retry. Run the upsert again."
                : "Ingest run [{$runId}] has no failed jobs to retry (status {$run->status}).");

            return self::FAILURE;
        }

        // queue:retry skips an id it cannot find and still succeeds, so keep only the jobs the failer still holds.
        $retryable = array_values(array_filter($failedJobIds, fn (string $id): bool => $failer->find($id) !== null));
        $gone = count($failedJobIds) - count($retryable);

        if ($retryable === []) {
            $this->error("The {$gone} failed job(s) of ingest run [{$runId}] are no longer in the failed job store (pruned or deleted), so they cannot be retried. Run the upsert again.");

            return self::FAILURE;
        }

        if ($gone > 0) {
            $this->warn("{$gone} failed job(s) of ingest run [{$runId}] are no longer in the failed job store; their batches stay failed.");
        }

        QueuedIngest::reopen($runId);
        $this->call('queue:retry', ['id' => $retryable]);
        $this->info('Retrying '.count($retryable)." failed batch job(s) of ingest run [{$runId}].");

        return self::SUCCESS;
    }
}
