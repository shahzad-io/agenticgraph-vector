<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tracing;

use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Events\VectorOperationFailed;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use Closure;
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

    private static int $depth = 0;

    private static ?int $embeddingMs = null;

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

    public static function isAgenticGraphTracingAvailable(): bool
    {
        return class_exists(self::TRACE_FACADE_CLASS)
            && app()->bound(self::TRACER_CLASS);
    }
}
