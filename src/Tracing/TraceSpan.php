<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tracing;

use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Events\VectorOperationFailed;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use AgenticGraph\Vector\ValueObjects\SearchResult;
use Closure;
use Throwable;

/**
 * The single soft-integration guard point for agenticgraph tracing.
 * When agenticgraph is absent, operations pass through with zero tracing overhead.
 * Package events always dispatch independently when enabled.
 */
final class TraceSpan
{
    private const TRACER_CLASS = 'Shahzad\AgenticGraph\Tracing\Tracer';

    private const TRACE_FACADE_CLASS = 'Shahzad\AgenticGraph\Facades\Trace';

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function search(SearchQuery $query, Closure $execute, ?VectorTraceContext $context = null): mixed
    {
        $context ??= VectorTraceContext::forSearch($query);

        return self::run('search', $execute, $context);
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
        return self::run('fetch', $execute, $context);
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
        return self::run('upsert', $execute, $context);
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
        return self::run('insert', $execute, $context);
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
        return self::run('delete', $execute, $context);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function deleteByFilter(NormalizedFilter $filter, Closure $execute, ?VectorTraceContext $context = null): mixed
    {
        return self::run('delete_by_filter', $execute, $context);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    private static function run(string $operation, Closure $execute, ?VectorTraceContext $context): mixed
    {
        $context ??= new VectorTraceContext(
            store: (string) config('vector.default', 'pgvector'),
            collection: 'unknown',
        );

        $started = hrtime(true);
        $attributes = VectorSpanSchema::baseAttributes($operation, $context);

        try {
            $result = self::executeWithOptionalAgenticGraphSpan(
                operation: $operation,
                execute: $execute,
                attributes: $attributes,
            );

            self::dispatchCompleted($operation, $context, $result, $started);

            return $result;
        } catch (Throwable $exception) {
            self::dispatchFailed($operation, $context, $exception, $started);

            throw $exception;
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
            $started = hrtime(true);
            $result = $execute();
            VectorSpanSchema::enrichFromResult(
                $attributes,
                $operation,
                $result,
                self::elapsedMilliseconds($started),
            );

            return $result;
        }

        // Deliberate string-based resolution — never add a hard Tracer import (breaks standalone).
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

        $resultCount = $result instanceof SearchResult ? $result->count() : null;

        $documentCount = in_array($operation, ['insert', 'upsert'], true)
            ? ($context->documentCount > 0 ? $context->documentCount : null)
            : null;

        event(new VectorOperationCompleted(
            operation: $operation,
            store: $context->store,
            collection: $context->collection,
            durationMs: self::elapsedMilliseconds($startedHrtime),
            resultCount: $resultCount,
            documentCount: $documentCount,
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
            documentCount: in_array($operation, ['insert', 'upsert'], true) && $context->documentCount > 0
                ? $context->documentCount
                : null,
        ));
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
