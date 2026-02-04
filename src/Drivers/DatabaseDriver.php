<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Drivers;

use DateTimeImmutable;
use Grazulex\ApiIdempotency\Contracts\StorageDriverInterface;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

class DatabaseDriver implements StorageDriverInterface
{
    protected ConnectionInterface $db;

    protected string $table;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        DatabaseManager $databaseManager,
        protected array $config,
        protected int $ttl,
    ) {
        $connection = $config['connection'] ?? null;
        $this->db = $connection
            ? $databaseManager->connection($connection)
            : $databaseManager->connection();
        $this->table = $config['table'] ?? 'idempotency_keys';
    }

    public function get(string $key, ?string $scope = null): ?IdempotencyRecord
    {
        $query = $this->db->table($this->table)
            ->where('key', $key)
            ->where('expires_at', '>', Carbon::now());

        if ($scope !== null) {
            $query->where('scope', $scope);
        } else {
            $query->whereNull('scope');
        }

        $row = $query->first();

        if ($row === null) {
            return null;
        }

        return $this->rowToRecord($row);
    }

    public function store(string $key, IdempotencyRecord $record, ?string $scope = null): bool
    {
        $exists = $this->db->table($this->table)
            ->where('key', $key)
            ->where('scope', $scope)
            ->exists();

        $data = [
            'key' => $key,
            'scope' => $scope,
            'fingerprint' => $record->fingerprint,
            'method' => $record->method,
            'path' => $record->path,
            'status_code' => $record->statusCode,
            'response_headers' => json_encode($record->responseHeaders, JSON_THROW_ON_ERROR),
            'response_body' => $record->responseBody,
            'response_size' => $record->responseSize,
            'state' => $record->state,
            'locked_until' => $record->lockedUntil?->format('Y-m-d H:i:s'),
            'created_at' => $record->createdAt->format('Y-m-d H:i:s'),
            'expires_at' => $record->expiresAt->format('Y-m-d H:i:s'),
        ];

        if ($exists) {
            return (bool) $this->db->table($this->table)
                ->where('key', $key)
                ->where('scope', $scope)
                ->update($data);
        }

        return (bool) $this->db->table($this->table)->insert($data);
    }

    public function lock(string $key, ?string $scope = null, int $timeout = 10): bool
    {
        $lockedUntil = Carbon::now()->addSeconds($timeout);

        // Try to insert a new processing record
        $exists = $this->db->table($this->table)
            ->where('key', $key)
            ->where('scope', $scope)
            ->exists();

        if (! $exists) {
            return (bool) $this->db->table($this->table)->insert([
                'key' => $key,
                'scope' => $scope,
                'method' => '',
                'path' => '',
                'status_code' => 0,
                'state' => 'processing',
                'locked_until' => $lockedUntil,
                'created_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addSeconds($this->ttl),
            ]);
        }

        // Try to acquire lock on existing record
        $affected = $this->db->table($this->table)
            ->where('key', $key)
            ->where('scope', $scope)
            ->where(function ($query) {
                $query->whereNull('locked_until')
                    ->orWhere('locked_until', '<', Carbon::now());
            })
            ->update([
                'state' => 'processing',
                'locked_until' => $lockedUntil,
            ]);

        return $affected > 0;
    }

    public function unlock(string $key, ?string $scope = null): bool
    {
        $affected = $this->db->table($this->table)
            ->where('key', $key)
            ->where('scope', $scope)
            ->update([
                'locked_until' => null,
            ]);

        return $affected > 0;
    }

    public function isLocked(string $key, ?string $scope = null): bool
    {
        return $this->db->table($this->table)
            ->where('key', $key)
            ->where('scope', $scope)
            ->where('state', 'processing')
            ->where('locked_until', '>', Carbon::now())
            ->exists();
    }

    public function forget(string $key, ?string $scope = null): bool
    {
        $query = $this->db->table($this->table)->where('key', $key);

        if ($scope !== null) {
            $query->where('scope', $scope);
        } else {
            $query->whereNull('scope');
        }

        return (bool) $query->delete();
    }

    public function cleanup(): int
    {
        return $this->db->table($this->table)
            ->where('expires_at', '<', Carbon::now())
            ->delete();
    }

    /**
     * @return array{total: int, hits: int, size: int}
     */
    public function stats(): array
    {
        $total = $this->db->table($this->table)
            ->where('expires_at', '>', Carbon::now())
            ->count();

        $size = $this->db->table($this->table)
            ->where('expires_at', '>', Carbon::now())
            ->sum('response_size');

        return [
            'total' => $total,
            'hits' => 0, // Would need additional tracking
            'size' => (int) $size,
        ];
    }

    /**
     * @return array<IdempotencyRecord>
     */
    public function list(int $limit = 20): array
    {
        $rows = $this->db->table($this->table)
            ->where('expires_at', '>', Carbon::now())
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => $this->rowToRecord($row))->all();
    }

    protected function rowToRecord(object $row): IdempotencyRecord
    {
        return new IdempotencyRecord(
            key: $row->key,
            scope: $row->scope,
            fingerprint: $row->fingerprint,
            method: $row->method,
            path: $row->path,
            statusCode: (int) $row->status_code,
            responseHeaders: $row->response_headers ? json_decode($row->response_headers, true) : null,
            responseBody: $row->response_body,
            responseSize: (int) $row->response_size,
            state: $row->state,
            createdAt: new DateTimeImmutable($row->created_at),
            expiresAt: new DateTimeImmutable($row->expires_at),
            lockedUntil: $row->locked_until ? new DateTimeImmutable($row->locked_until) : null,
        );
    }
}
