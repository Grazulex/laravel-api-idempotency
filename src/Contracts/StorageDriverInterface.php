<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Contracts;

use Grazulex\ApiIdempotency\Support\IdempotencyRecord;

interface StorageDriverInterface
{
    /**
     * Get a stored idempotency record.
     */
    public function get(string $key, ?string $scope = null): ?IdempotencyRecord;

    /**
     * Store an idempotency record.
     */
    public function store(string $key, IdempotencyRecord $record, ?string $scope = null): bool;

    /**
     * Lock a key for processing (returns false if already locked).
     */
    public function lock(string $key, ?string $scope = null, int $timeout = 10): bool;

    /**
     * Unlock a key.
     */
    public function unlock(string $key, ?string $scope = null): bool;

    /**
     * Check if a key is currently locked.
     */
    public function isLocked(string $key, ?string $scope = null): bool;

    /**
     * Delete a specific key.
     */
    public function forget(string $key, ?string $scope = null): bool;

    /**
     * Delete all expired records.
     */
    public function cleanup(): int;

    /**
     * Get statistics about stored records.
     *
     * @return array{total: int, hits: int, size: int}
     */
    public function stats(): array;

    /**
     * Get a list of recent records.
     *
     * @return array<IdempotencyRecord>
     */
    public function list(int $limit = 20): array;
}
