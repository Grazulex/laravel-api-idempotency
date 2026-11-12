<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Http\Client;

use Grazulex\ApiIdempotency\Support\IdempotencyKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Configures an outgoing HTTP client request with an idempotency key.
 *
 * Backs the `withIdempotencyKey()` macro registered on the Laravel HTTP client.
 */
class IdempotentRequest
{
    /**
     * HTTP status codes that are safe to retry with the same idempotency key.
     *
     * @var list<int>
     */
    public const RETRYABLE_STATUSES = [409, 425, 429];

    /**
     * Attach an idempotency key header to the request and optionally enable retries.
     *
     * The key is resolved once, so every retry attempt sends the same key and the
     * remote API can safely replay the original response instead of duplicating it.
     */
    public static function apply(
        PendingRequest $request,
        ?string $key = null,
        int $retries = 0,
        int $sleepMilliseconds = 100,
        ?string $prefix = null,
    ): PendingRequest {
        $key ??= IdempotencyKey::generate($prefix);

        $request->withHeaders([self::headerName() => $key]);

        if ($retries > 0) {
            $request->retry(
                $retries + 1,
                max(0, $sleepMilliseconds),
                self::shouldRetry(...),
                false,
            );
        }

        return $request;
    }

    /**
     * Determine whether a failed attempt should be retried.
     */
    public static function shouldRetry(?Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $status >= 500 || in_array($status, self::RETRYABLE_STATUSES, true);
        }

        return false;
    }

    /**
     * The header name used to send the idempotency key.
     */
    public static function headerName(): string
    {
        $header = config('api-idempotency.header', 'Idempotency-Key');

        return is_string($header) && $header !== '' ? $header : 'Idempotency-Key';
    }
}
