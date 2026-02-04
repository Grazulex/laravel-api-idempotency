<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Drivers;

use Grazulex\ApiIdempotency\Contracts\StorageDriverInterface;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;

class CacheDriver implements StorageDriverInterface
{
    protected Repository $cache;

    protected string $prefix;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        CacheManager $cacheManager,
        protected array $config,
        protected int $ttl,
    ) {
        $store = $config['store'] ?? 'default';
        $this->cache = $store === 'default'
            ? $cacheManager->store()
            : $cacheManager->store($store);
        $this->prefix = $config['prefix'] ?? 'idempotency:';
    }

    public function get(string $key, ?string $scope = null): ?IdempotencyRecord
    {
        $cacheKey = $this->buildKey($key, $scope);
        $data = $this->cache->get($cacheKey);

        if ($data === null) {
            return null;
        }

        return IdempotencyRecord::fromArray($data);
    }

    public function store(string $key, IdempotencyRecord $record, ?string $scope = null): bool
    {
        $cacheKey = $this->buildKey($key, $scope);

        return $this->cache->put($cacheKey, $record->toArray(), $this->ttl);
    }

    public function lock(string $key, ?string $scope = null, int $timeout = 10): bool
    {
        $lockKey = $this->buildKey($key, $scope).':lock';

        return $this->cache->add($lockKey, true, $timeout);
    }

    public function unlock(string $key, ?string $scope = null): bool
    {
        $lockKey = $this->buildKey($key, $scope).':lock';

        return $this->cache->forget($lockKey);
    }

    public function isLocked(string $key, ?string $scope = null): bool
    {
        $lockKey = $this->buildKey($key, $scope).':lock';

        return $this->cache->has($lockKey);
    }

    public function forget(string $key, ?string $scope = null): bool
    {
        $cacheKey = $this->buildKey($key, $scope);
        $this->unlock($key, $scope);

        return $this->cache->forget($cacheKey);
    }

    public function cleanup(): int
    {
        // Cache driver handles expiration automatically via TTL
        return 0;
    }

    /**
     * @return array{total: int, hits: int, size: int}
     */
    public function stats(): array
    {
        // Cache driver doesn't support stats easily
        return [
            'total' => 0,
            'hits' => 0,
            'size' => 0,
        ];
    }

    /**
     * @return array<IdempotencyRecord>
     */
    public function list(int $limit = 20): array
    {
        // Cache driver doesn't support listing
        return [];
    }

    protected function buildKey(string $key, ?string $scope = null): string
    {
        $parts = [$this->prefix, $key];

        if ($scope !== null) {
            array_splice($parts, 1, 0, $scope);
        }

        return implode('', $parts);
    }
}
