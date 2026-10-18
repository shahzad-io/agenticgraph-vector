<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tracing;

use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Events\VectorOperationFailed;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\RerankResult;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The single soft-integration guard point for agenticgraph tracing.
 * When agenticgraph is absent, operations pass through with zero tracing overhead.
 * Package events always dispatch independently when enabled.
 *
 * The outermost operation owns the span and the event: an operation started inside another
 * (Builder::get() calling the instrumented store's search) passes straight through.
 */
final class TraceSpan
{
    private const TRACER_CLASS = 'Shahzad\AgenticGraph\Tracing\Tracer';

    private const TRACE_FACADE_CLASS = 'Shahzad\AgenticGraph\Facades\Trace';

    private const TRACE_CONTEXT_CLASS = 'Shahzad\AgenticGraph\Tracing\TraceContext';

    private const EXPORTER_CLASS = 'Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter';

    private const EXECUTION_CLOCK_CLASS = 'Shahzad\AgenticGraph\Tracing\ExecutionClock';

    private const HTTP_INSTRUMENTATION_CLASS = 'Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation';

    private static int $depth = 0;

    private static ?int $embeddingMs = null;

    /** @var array{ms: int, candidates: int, status: string}|null */
    private static ?array $rerank = null;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function search(SearchQuery $query, Closure $execute, ?VectorTraceContext $context = null): mixed
    {
        return self::operation('search', $execute, $context ?? VectorTraceContext::forSearch($query));
    }

    /**
     * @param  list<string|int>  $ids
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function fetch(array $ids, Closure $execute, ?VectorTraceContext $context = null): mixed
    {
        return self::operation('fetch', $execute, $context);
    }

    /**
     * @param  list<Document>  $documents
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function upsert(array $documents, Closure $execute, ?VectorTraceContext $context = null): mixed
    {
        return self::operation('upsert', $execute, $context);
    }

    /**
     * @param  list<Document>  $documents
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function insert(array $documents, Closure $execute, ?VectorTraceContext $context = null): mixed
    {
        return self::operation('insert', $execute, $context);
    }

    /**
     * @param  list<string|int>|string|int  $ids
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function delete(array|string|int $ids, Closure $execute, ?VectorTraceContext $context = null): mixed
    {
        return self::operation('delete', $execute, $context);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function deleteByFilter(NormalizedFilter $filter, Closure $execute, ?VectorTraceContext $context = null): mixed
    {
        return self::operation('delete_by_filter', $execute, $context);
    }

    /**
     * Run one store operation with its span and event. Span name: `vector.{$operation}`.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function operation(string $operation, Closure $execute, ?VectorTraceContext $context = null): mixed
    {
        if (self::$depth > 0) {
            return $execute();
        }

        $context ??= new VectorTraceContext(
            store: (string) config('vector.default', 'pgvector'),
            collection: 'unknown',
        );

        $started = hrtime(true);
        $attributes = VectorSpanSchema::baseAttributes($operation, $context);

        self::$depth++;
        self::$embeddingMs = null;
        self::$rerank = null;

        try {
            $result = self::executeWithOptionalAgenticGraphSpan(
                operation: $operation,
                execute: $execute,
                attributes: $attributes,
            );
        } catch (Throwable $exception) {
            self::$depth--;
            self::dispatchFailed($operation, $context, $exception, $started);

            throw $exception;
        }

        self::$depth--;
        self::dispatchCompleted($operation, $context, $result, $started);

        return $result;
    }

    /**
     * Time a query embedding. Inside an operation the time lands on its span (`vector.embedding_ms`)
     * and its event (`embeddingMs`), also when the embedding fails.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $embed
     * @return TReturn
     */
    public static function embedding(Closure $embed): mixed
    {
        $started = hrtime(true);

        try {
            return $embed();
        } finally {
            self::$embeddingMs = (self::$embeddingMs ?? 0) + self::elapsedMilliseconds($started);
        }
    }

    /**
     * Run a search's rerank. Inside an operation its time, the hits sent and `ok` or `failed_open` land on the
     * span (`vector.rerank_*`), and the time on the event (`rerankMs`), also when the reranker throws.
     *
     * @param  Closure(): RerankResult  $rerank
     */
    public static function rerank(int $candidates, Closure $rerank): RerankResult
    {
        $started = hrtime(true);
        $status = 'failed';

        try {
            $result = $rerank();
            $status = $result->failedOpen ? 'failed_open' : 'ok';

            return $result;
        } finally {
            self::$rerank = ['ms' => self::elapsedMilliseconds($started), 'candidates' => $candidates, 'status' => $status];
        }
    }

    /**
     * @template TReturn
     *
     * @param  array<string, int|string|float>  $attributes
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    private static function executeWithOptionalAgenticGraphSpan(
        string $operation,
        Closure $execute,
        array $attributes,
    ): mixed {
        if (! self::isAgenticGraphTracingAvailable()) {
            return $execute();
        }

        // Deliberate string-based resolution: never add a hard Tracer import (breaks standalone).
        // See tests/Unit/Tracing/TraceSpanSoftBoundaryTest.php.
        $tracer = app()->make(self::TRACER_CLASS);

        return $tracer->span(
            "vector.{$operation}",
            function () use ($execute, &$attributes, $operation) {
                $started = hrtime(true);
                $result = $execute();
                VectorSpanSchema::enrichFromResult(
                    $attributes,
                    $operation,
                    $result,
                    self::elapsedMilliseconds($started),
                    self::$embeddingMs,
                    self::$rerank,
                );

                return $result;
            },
            $attributes,
            'vector',
        );
    }

    private static function dispatchCompleted(
        string $operation,
        VectorTraceContext $context,
        mixed $result,
        int $startedHrtime,
    ): void {
        if (! self::eventsEnabled()) {
            return;
        }

        event(new VectorOperationCompleted(
            operation: $operation,
            store: $context->store,
            collection: $context->collection,
            durationMs: self::elapsedMilliseconds($startedHrtime),
            resultCount: VectorSpanSchema::resultCount($result),
            documentCount: self::documentCount($operation, $context),
            embeddingMs: self::$embeddingMs,
            searchMode: $context->searchMode,
            rerankMs: self::$rerank['ms'] ?? null,
        ));
    }

    private static function dispatchFailed(
        string $operation,
        VectorTraceContext $context,
        Throwable $exception,
        int $startedHrtime,
    ): void {
        if (! self::eventsEnabled()) {
            return;
        }

        event(new VectorOperationFailed(
            operation: $operation,
            store: $context->store,
            collection: $context->collection,
            durationMs: self::elapsedMilliseconds($startedHrtime),
            exception: $exception,
            documentCount: self::documentCount($operation, $context),
            embeddingMs: self::$embeddingMs,
            searchMode: $context->searchMode,
            rerankMs: self::$rerank['ms'] ?? null,
        ));
    }

    private static function documentCount(string $operation, VectorTraceContext $context): ?int
    {
        return in_array($operation, ['insert', 'upsert', 'update_metadata'], true) && $context->documentCount > 0
            ? $context->documentCount
            : null;
    }

    private static function eventsEnabled(): bool
    {
        return (bool) config('vector.events.enabled', true);
    }

    private static function elapsedMilliseconds(int $startedHrtime): int
    {
        return (int) round((hrtime(true) - $startedHrtime) / 1_000_000);
    }

    /**
     * Run $execute as its own trace (a root `graph` span named $name) when agenticgraph tracing is on and
     * no trace is running. For commands such as vector:migrate, which run outside any graph.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function rootTrace(string $name, Closure $execute): mixed
    {
        $exporter = self::exporter();

        if ($exporter === null || self::traceContext() !== null) {
            return $execute();
        }

        $traceId = 'vector_'.bin2hex(random_bytes(12));
        $rootSpanId = uniqid('span_', true);
        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $exporter->startTrace($traceId, $name, [], $startedAt);
        app()->instance(self::TRACE_CONTEXT_CLASS, self::newTraceContext($traceId, $rootSpanId));
        $failure = null;

        try {
            return $execute();
        } catch (Throwable $exception) {
            $failure = $exception;

            throw $exception;
        } finally {
            app()->forgetInstance(self::TRACE_CONTEXT_CLASS);
            $endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $durationMs = ((float) $endedAt->format('U.u') - (float) $startedAt->format('U.u')) * 1000;
            $exporter->addSpan([
                'id' => $rootSpanId,
                'trace_id' => $traceId,
                'parent_id' => null,
                'node_name' => $name,
                'node_type' => 'graph',
                'span_type' => 'graph',
                'status' => $failure === null ? 'success' : 'failed',
                'span_kind' => 'internal',
                'status_code' => $failure === null ? 'OK' : 'ERROR',
                'started_at' => $startedAt->format('Y-m-d\TH:i:s.uP'),
                'ended_at' => $endedAt->format('Y-m-d\TH:i:s.uP'),
                'duration_ms' => $durationMs,
            ] + ($failure === null ? [] : ['error' => ['type' => $failure::class, 'message' => $failure->getMessage()]]));
            $failure === null
                ? $exporter->completeTrace($traceId, [], $durationMs, null, $endedAt)
                : $exporter->failTrace($traceId, $failure, $durationMs, null, $endedAt);
        }
    }

    /**
     * The running trace, its root span and the current span, for a queued job to carry ({@see continueTrace()}),
     * or null outside a trace.
     *
     * @return array{trace_id: string, root_span_id: string, span_id: string}|null
     */
    public static function carry(): ?array
    {
        $context = self::exporter() !== null ? self::traceContext() : null;
        $spanId = $context?->getSpanId();

        return $context !== null && $context->isEnabled() && is_string($spanId)
            ? ['trace_id' => $context->getExecutionId(), 'root_span_id' => $context->getRootSpanId(), 'span_id' => $spanId]
            : null;
    }

    /**
     * Run a queued job's work inside the trace it carried, sent as a `continued` segment when the work ends. The
     * work usually ends after the span that queued it (often after the run), so its spans hang off the trace root
     * and link to that span (trace wire v1.1). Inline (a trace already bound in this process) it just runs.
     * Core records HTTP requests (with `capture.http`) only under an execution clock, so the work gets one for the
     * carried trace unless one is bound; afterwards core's per-trace HTTP state for that trace is cleaned up.
     *
     * @param  array{trace_id: string, root_span_id: string, span_id: string}|null  $carried
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function continueTrace(?array $carried, Closure $execute): mixed
    {
        $exporter = self::exporter();

        if ($carried === null || $exporter === null || self::traceContext() !== null) {
            return $execute();
        }

        $link = ['trace_id' => $carried['trace_id'], 'span_id' => $carried['span_id']];
        app()->instance(self::TRACE_CONTEXT_CLASS, self::newTraceContext($carried['trace_id'], $carried['root_span_id'], [$link]));
        $ownClock = class_exists(self::EXECUTION_CLOCK_CLASS) && ! app()->bound(self::EXECUTION_CLOCK_CLASS);

        if ($ownClock) {
            app()->instance(self::EXECUTION_CLOCK_CLASS, app()->make(self::EXECUTION_CLOCK_CLASS, ['executionId' => $carried['trace_id']]));
        }

        $exporter->resumeTrace($carried['trace_id'], null, 'continued');

        try {
            return $execute();
        } finally {
            app()->forgetInstance(self::TRACE_CONTEXT_CLASS);

            if ($ownClock) {
                app()->forgetInstance(self::EXECUTION_CLOCK_CLASS);

                if (app()->bound(self::HTTP_INSTRUMENTATION_CLASS)) {
                    app()->make(self::HTTP_INSTRUMENTATION_CLASS)->cleanupExecution($carried['trace_id']);
                }
            }

            $exporter->flush($carried['trace_id']);
        }
    }

    /**
     * agenticgraph's trace exporter when tracing is available and the exporter is on, else null.
     */
    private static function exporter(): ?object
    {
        if (! self::isAgenticGraphTracingAvailable() || ! app()->bound(self::EXPORTER_CLASS)) {
            return null;
        }

        $exporter = app()->make(self::EXPORTER_CLASS);

        return $exporter->isEnabled() ? $exporter : null;
    }

    /**
     * @param  list<array{trace_id: string, span_id: string}>  $links
     */
    private static function newTraceContext(string $traceId, string $rootSpanId, array $links = []): object
    {
        return app()->make(self::TRACE_CONTEXT_CLASS, ['executionId' => $traceId, 'rootSpanId' => $rootSpanId, 'links' => $links]);
    }

    private static function traceContext(): ?object
    {
        return app()->bound(self::TRACE_CONTEXT_CLASS) ? app()->make(self::TRACE_CONTEXT_CLASS) : null;
    }

    public static function isAgenticGraphTracingAvailable(): bool
    {
        return class_exists(self::TRACE_FACADE_CLASS)
            && app()->bound(self::TRACER_CLASS);
    }
}
