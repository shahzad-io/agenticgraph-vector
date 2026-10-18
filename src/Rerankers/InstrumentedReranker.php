<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Rerankers;

use AgenticGraph\Vector\Contracts\RerankerContract;
use AgenticGraph\Vector\Events\VectorRerankCompleted;
use AgenticGraph\Vector\Events\VectorRerankFailed;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\ValueObjects\RerankResult;
use Throwable;

/**
 * Wraps every reranker the manager returns: checks topN, skips empty input, fires VectorRerankCompleted or
 * VectorRerankFailed, and applies the fail-open policy (on failure, the first topN input hits, unchanged).
 */
final class InstrumentedReranker implements RerankerContract
{
    public function __construct(
        private readonly string $name,
        private readonly string $model,
        private readonly RerankerContract $reranker,
        private readonly bool $failOpen,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function rerank(string $query, array $hits, int $topN): RerankResult
    {
        if ($topN < 1) {
            throw new VectorQueryException("Rerank topN must be at least 1. Got [{$topN}].");
        }

        if ($hits === []) {
            return new RerankResult([], $this->model);
        }

        $started = hrtime(true);

        try {
            $result = $this->reranker->rerank($query, $hits, $topN);
        } catch (Throwable $exception) {
            $this->dispatch(new VectorRerankFailed($this->name, $this->model, count($hits), self::ms($started), $exception, $this->failOpen));

            if (! $this->failOpen) {
                throw $exception;
            }

            return new RerankResult(array_slice($hits, 0, $topN), $this->model, failedOpen: true);
        }

        $this->dispatch(new VectorRerankCompleted($this->name, $result->model, count($hits), $result->units, $result->tokens, self::ms($started), $result->inputTokens, $result->outputTokens));

        return $result;
    }

    private function dispatch(object $event): void
    {
        if ((bool) config('vector.events.enabled', true)) {
            event($event);
        }
    }

    private static function ms(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
