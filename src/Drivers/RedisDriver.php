<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Drivers;

use Grazulex\ApiIdempotency\Contracts\StorageDriverInterface;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;
use Illuminate\Redis\RedisManager;

class RedisDriver implements StorageDriverInterface
{
    protected mixed $redis;

    protected string $prefix;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        RedisManager $redisManager,
        protected array $config,
        protected int $ttl,
    ) {
        $connection = $config['connection'] ?? 'default';
        $this->redis = $redisManager->connection($connection);
        $this->prefix = $config['prefix'] ?? 'idempotency:';
    }

    public function get(string $key, ?string $scope = null): ?IdempotencyRecord
    {
        $redisKey = $this->buildKey($key, $scope);
        $data = $this->redis->get($redisKey);

        if ($data === null) {
            return null;
        }

        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

        return IdempotencyRecord::fromArray($decoded);
    }

    public function store(string $key, IdempotencyRecord $record, ?string $scope = null): bool
    {
        $redisKey = $this->buildKey($key, $scope);
        $data = json_encode($record->toArray(), JSON_THROW_ON_ERROR);

        return (bool) $this->redis->setex($redisKey, $this->ttl, $data);
    }

    public function lock(string $key, ?string $scope = null, int $timeout = 10): bool
    {
        $lockKey = $this->buildKey($key, $scope).':lock';

        return (bool) $this->redis->set($lockKey, '1', 'EX', $timeout, 'NX');
    }

    public function unlock(string $key, ?string $scope = null): bool
    {
        $lockKey = $this->buildKey($key, $scope).':lock';

        return (bool) $this->redis->del($lockKey);
    }

    public function isLocked(string $key, ?string $scope = null): bool
    {
        $lockKey = $this->buildKey($key, $scope).':lock';

        return (bool) $this->redis->exists($lockKey);
    }

    public function forget(string $key, ?string $scope = null): bool
    {
        $redisKey = $this->buildKey($key, $scope);
        $this->unlock($key, $scope);

        return (bool) $this->redis->del($redisKey);
    }

    public function cleanup(): int
    {
        // Redis handles expiration automatically via TTL
        return 0;
    }

    /**
     * @return array{total: int, hits: int, size: int}
     */
    public function stats(): array
    {
        $pattern = $this->prefix.'*';
        $keys = $this->redis->keys($pattern);
        $totalKeys = count(array_filter($keys, fn ($k) => ! str_ends_with($k, ':lock')));

        return [
            'total' => $totalKeys,
            'hits' => 0,
            'size' => 0,
        ];
    }

    /**
     * @return array<IdempotencyRecord>
     */
    public function list(int $limit = 20): array
    {
        $pattern = $this->prefix.'*';
        $keys = $this->redis->keys($pattern);
        $keys = array_filter($keys, fn ($k) => ! str_ends_with($k, ':lock'));
        $keys = array_slice($keys, 0, $limit);

        $records = [];
        foreach ($keys as $redisKey) {
            $data = $this->redis->get($redisKey);
            if ($data !== null) {
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                $records[] = IdempotencyRecord::fromArray($decoded);
            }
        }

        return $records;
    }

    protected function buildKey(string $key, ?string $scope = null): string
    {
        $parts = [$this->prefix];

        if ($scope !== null) {
            $parts[] = $scope.':';
        }

        $parts[] = $key;

        return implode('', $parts);
    }
}
