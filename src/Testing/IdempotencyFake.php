<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Testing;

use DateTimeImmutable;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class IdempotencyFake
{
    /**
     * @var array<string, IdempotencyRecord>
     */
    protected array $stored = [];

    /**
     * @var array<string, int>
     */
    protected array $replayed = [];

    protected bool $shouldSkip = false;

    public function get(string $key, ?string $scope = null): ?IdempotencyRecord
    {
        $compositeKey = $this->buildKey($key, $scope);

        return $this->stored[$compositeKey] ?? null;
    }

    public function store(string $key, SymfonyResponse $response, ?string $scope = null, ?string $fingerprint = null, ?Request $request = null): bool
    {
        $compositeKey = $this->buildKey($key, $scope);

        $this->stored[$compositeKey] = new IdempotencyRecord(
            key: $key,
            scope: $scope,
            fingerprint: $fingerprint,
            method: $request?->method() ?? 'POST',
            path: $request?->path() ?? '',
            statusCode: $response->getStatusCode(),
            responseHeaders: $response->headers->all(),
            responseBody: $response->getContent() ?: null,
            responseSize: strlen($response->getContent() ?: ''),
            state: 'completed',
            createdAt: new DateTimeImmutable,
            expiresAt: (new DateTimeImmutable)->modify('+24 hours'),
        );

        return true;
    }

    public function forget(string $key, ?string $scope = null): bool
    {
        $compositeKey = $this->buildKey($key, $scope);
        unset($this->stored[$compositeKey]);

        return true;
    }

    public function skip(): void
    {
        $this->shouldSkip = true;
    }

    public function shouldSkip(): bool
    {
        return $this->shouldSkip;
    }

    public function resetSkip(): void
    {
        $this->shouldSkip = false;
    }

    /**
     * @return array{total: int, hits: int, size: int}
     */
    public function stats(): array
    {
        $size = array_sum(array_map(fn ($r) => $r->responseSize, $this->stored));
        $hits = array_sum($this->replayed);

        return [
            'total' => count($this->stored),
            'hits' => $hits,
            'size' => $size,
        ];
    }

    /**
     * @return array<IdempotencyRecord>
     */
    public function list(int $limit = 20): array
    {
        return array_slice(array_values($this->stored), 0, $limit);
    }

    public function cleanup(): int
    {
        $count = count($this->stored);
        $this->stored = [];

        return $count;
    }

    /**
     * Record that a key was replayed.
     */
    public function recordReplay(string $key, ?string $scope = null): void
    {
        $compositeKey = $this->buildKey($key, $scope);
        $this->replayed[$compositeKey] = ($this->replayed[$compositeKey] ?? 0) + 1;
    }

    // Assertions

    /**
     * Assert that a key was stored.
     */
    public function assertStored(string $key, ?string $scope = null): void
    {
        $compositeKey = $this->buildKey($key, $scope);

        Assert::assertTrue(
            isset($this->stored[$compositeKey]),
            "Expected idempotency key [{$key}] to be stored, but it was not."
        );
    }

    /**
     * Assert that a key was not stored.
     */
    public function assertNotStored(string $key, ?string $scope = null): void
    {
        $compositeKey = $this->buildKey($key, $scope);

        Assert::assertFalse(
            isset($this->stored[$compositeKey]),
            "Expected idempotency key [{$key}] not to be stored, but it was."
        );
    }

    /**
     * Assert that a key was replayed.
     */
    public function assertReplayed(string $key, ?string $scope = null): void
    {
        $compositeKey = $this->buildKey($key, $scope);

        Assert::assertTrue(
            isset($this->replayed[$compositeKey]) && $this->replayed[$compositeKey] > 0,
            "Expected idempotency key [{$key}] to be replayed, but it was not."
        );
    }

    /**
     * Assert that a key was not replayed.
     */
    public function assertNotReplayed(string $key, ?string $scope = null): void
    {
        $compositeKey = $this->buildKey($key, $scope);

        Assert::assertFalse(
            isset($this->replayed[$compositeKey]) && $this->replayed[$compositeKey] > 0,
            "Expected idempotency key [{$key}] not to be replayed, but it was."
        );
    }

    /**
     * Assert the total number of stored keys.
     */
    public function assertStoredCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->stored,
            "Expected {$count} stored idempotency keys, got ".count($this->stored).'.'
        );
    }

    /**
     * Assert nothing was stored.
     */
    public function assertNothingStored(): void
    {
        Assert::assertEmpty(
            $this->stored,
            'Expected no idempotency keys to be stored, but '.count($this->stored).' were.'
        );
    }

    /**
     * Assert that the stored response has a specific status code.
     */
    public function assertStoredWithStatus(string $key, int $statusCode, ?string $scope = null): void
    {
        $this->assertStored($key, $scope);

        $compositeKey = $this->buildKey($key, $scope);
        $record = $this->stored[$compositeKey];

        Assert::assertEquals(
            $statusCode,
            $record->statusCode,
            "Expected idempotency key [{$key}] to have status code {$statusCode}, got {$record->statusCode}."
        );
    }

    protected function buildKey(string $key, ?string $scope = null): string
    {
        return $scope !== null ? "{$scope}:{$key}" : $key;
    }
}
