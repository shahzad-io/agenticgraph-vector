<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tracing;

use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use Closure;

/**
 * The single soft-integration guard point for agenticgraph tracing.
 * When agenticgraph is absent, all operations pass through with zero overhead.
 */
final class TraceSpan
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function search(SearchQuery $query, Closure $execute): mixed
    {
        return self::run('search', $execute);
    }

    /**
     * @param  list<string|int>  $ids
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function fetch(array $ids, Closure $execute): mixed
    {
        return self::run('fetch', $execute);
    }

    /**
     * @param  list<Document>  $documents
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function upsert(array $documents, Closure $execute): mixed
    {
        return self::run('upsert', $execute);
    }

    /**
     * @param  list<Document>  $documents
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function insert(array $documents, Closure $execute): mixed
    {
        return self::run('insert', $execute);
    }

    /**
     * @param  list<string|int>|string|int  $ids
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function delete(array|string|int $ids, Closure $execute): mixed
    {
        return self::run('delete', $execute);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    public static function deleteByFilter(NormalizedFilter $filter, Closure $execute): mixed
    {
        return self::run('delete', $execute);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $execute
     * @return TReturn
     */
    private static function run(string $operation, Closure $execute): mixed
    {
        if (self::isAgenticGraphTracingAvailable()) {
            // Pending: wire Trace::span with frozen vector span schema.
            return $execute();
        }

        return $execute();
    }

    public static function isAgenticGraphTracingAvailable(): bool
    {
        return class_exists('Shahzad\AgenticGraph\Facades\Trace')
            && app()->bound('Shahzad\AgenticGraph\Tracing\Tracer');
    }
}
