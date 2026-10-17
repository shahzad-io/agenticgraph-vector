<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The retry rule for embedding and rerank requests, decided on the raw HTTP client exception (before Prism maps
 * it): 429, 5xx (529 included) and connection errors or timeouts retry; every other status does not.
 */
final class HttpRetry
{
    public static function retryable(?Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        $status = self::status($exception);

        return $status === 429 || $status >= 500;
    }

    public static function status(?Throwable $exception): ?int
    {
        return $exception instanceof RequestException ? $exception->response->status() : null;
    }

    /**
     * The raw HTTP exception behind $exception (it or a previous one), else $fallback: the last one the retry
     * callback saw, for errors Prism maps without keeping the cause (429, 529, 413).
     */
    public static function cause(Throwable $exception, ?Throwable $fallback): ?Throwable
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RequestException || $cause instanceof ConnectionException) {
                return $cause;
            }
        }

        return $fallback;
    }

    /**
     * Whether an embedding request retries: the error is retryable and the provider's Retry-After, if any, is at
     * most $retryAfterMaxMs. A longer Retry-After fails the request (still retryable) rather than sleeping.
     */
    public static function shouldRetry(?Throwable $exception, int $retryAfterMaxMs): bool
    {
        return self::retryable($exception) && self::retryAfterMs($exception) <= $retryAfterMaxMs;
    }

    /**
     * Milliseconds before retry $retry (1-based): a random wait between half and all of sleep x 2^(retry - 1),
     * or the provider's Retry-After when that is longer.
     */
    public static function delayMs(int $retry, int $sleepMs, ?Throwable $exception = null): int
    {
        $ceiling = $sleepMs * 2 ** max(0, $retry - 1);

        return max(random_int(intdiv($ceiling, 2), $ceiling), self::retryAfterMs($exception));
    }

    /**
     * Run $attempt (given the seconds left) until it returns, fails with a non-retryable error, has used
     * $retries retries, or the next try (after its wait, Retry-After included) would start past the $budget
     * seconds; then its last error is thrown.
     * So the whole call, sleeps included, ends within the budget plus connection teardown.
     *
     * @template TReturn
     *
     * @param  callable(float): TReturn  $attempt
     * @return TReturn
     */
    public static function withinBudget(float $budget, int $retries, int $sleepMs, callable $attempt): mixed
    {
        $deadline = hrtime(true) + (int) ($budget * 1e9);

        for ($retry = 1; ; $retry++) {
            try {
                return $attempt(max(0.001, ($deadline - hrtime(true)) / 1e9));
            } catch (Throwable $exception) {
                $cause = self::cause($exception, null);
                $sleep = self::delayMs($retry, $sleepMs, $cause);

                if ($retry > $retries || ! self::retryable($cause) || hrtime(true) + $sleep * 1_000_000 >= $deadline) {
                    throw $exception;
                }

                Sleep::usleep($sleep * 1000);
            }
        }
    }

    private static function retryAfterMs(?Throwable $exception): int
    {
        $header = $exception instanceof RequestException ? trim($exception->response->header('Retry-After')) : '';

        if ($header === '') {
            return 0;
        }

        $seconds = is_numeric($header) ? (float) $header : (float) ((strtotime($header) ?: time()) - time());

        return max(0, (int) ceil($seconds * 1000));
    }
}
