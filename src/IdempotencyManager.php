<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency;

use DateTimeImmutable;
use Grazulex\ApiIdempotency\Contracts\StorageDriverInterface;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class IdempotencyManager
{
    protected bool $shouldSkip = false;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected StorageDriverInterface $driver,
        protected array $config,
    ) {}

    /**
     * Get a stored idempotency record.
     */
    public function get(string $key, ?string $scope = null): ?IdempotencyRecord
    {
        return $this->driver->get($key, $scope);
    }

    /**
     * Store a response for an idempotency key.
     */
    public function store(string $key, SymfonyResponse $response, ?string $scope = null, ?string $fingerprint = null, ?Request $request = null): bool
    {
        $body = $response->getContent() ?: null;
        $bodySize = $body !== null ? strlen($body) : 0;

        // Check max body size
        $maxBodySize = $this->config['storage']['max_body_size'] ?? 1048576;
        if ($bodySize > $maxBodySize) {
            $body = null;
            $bodySize = 0;
        }

        // Filter headers
        $headers = $response->headers->all();
        $excludeHeaders = array_map('strtolower', $this->config['storage']['exclude_headers'] ?? []);
        $headers = array_filter(
            $headers,
            fn ($headerName) => ! in_array(strtolower($headerName), $excludeHeaders, true),
            ARRAY_FILTER_USE_KEY
        );

        $ttl = $this->config['ttl'] ?? 86400;
        $now = new DateTimeImmutable;

        $record = new IdempotencyRecord(
            key: $key,
            scope: $scope,
            fingerprint: $fingerprint,
            method: $request?->method() ?? 'POST',
            path: $request?->path() ?? '',
            statusCode: $response->getStatusCode(),
            responseHeaders: $headers,
            responseBody: $body,
            responseSize: $bodySize,
            state: 'completed',
            createdAt: $now,
            expiresAt: $now->modify("+{$ttl} seconds"),
        );

        return $this->driver->store($key, $record, $scope);
    }

    /**
     * Lock a key for processing.
     */
    public function lock(string $key, ?string $scope = null, ?int $timeout = null): bool
    {
        $timeout = $timeout ?? ($this->config['conflict']['wait_timeout'] ?? 10);

        return $this->driver->lock($key, $scope, $timeout);
    }

    /**
     * Unlock a key.
     */
    public function unlock(string $key, ?string $scope = null): bool
    {
        return $this->driver->unlock($key, $scope);
    }

    /**
     * Check if a key is currently locked.
     */
    public function isLocked(string $key, ?string $scope = null): bool
    {
        return $this->driver->isLocked($key, $scope);
    }

    /**
     * Delete a specific key.
     */
    public function forget(string $key, ?string $scope = null): bool
    {
        return $this->driver->forget($key, $scope);
    }

    /**
     * Mark the current request to skip idempotency caching.
     */
    public function skip(): void
    {
        $this->shouldSkip = true;
    }

    /**
     * Check if the current request should skip caching.
     */
    public function shouldSkip(): bool
    {
        return $this->shouldSkip;
    }

    /**
     * Reset the skip flag.
     */
    public function resetSkip(): void
    {
        $this->shouldSkip = false;
    }

    /**
     * Get statistics about stored records.
     *
     * @return array{total: int, hits: int, size: int}
     */
    public function stats(): array
    {
        return $this->driver->stats();
    }

    /**
     * Get a list of recent records.
     *
     * @return array<IdempotencyRecord>
     */
    public function list(int $limit = 20): array
    {
        return $this->driver->list($limit);
    }

    /**
     * Clean up expired records.
     */
    public function cleanup(): int
    {
        return $this->driver->cleanup();
    }

    /**
     * Check if idempotency is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    /**
     * Get the header name for idempotency key.
     */
    public function getHeaderName(): string
    {
        return $this->config['header'] ?? 'Idempotency-Key';
    }

    /**
     * Check if idempotency key is required.
     */
    public function isKeyRequired(): bool
    {
        return (bool) ($this->config['key']['required'] ?? false);
    }

    /**
     * Get the configured HTTP methods.
     *
     * @return array<string>
     */
    public function getMethods(): array
    {
        return $this->config['methods'] ?? ['POST'];
    }

    /**
     * Get the underlying driver.
     */
    public function getDriver(): StorageDriverInterface
    {
        return $this->driver;
    }

    /**
     * Get configuration value.
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
