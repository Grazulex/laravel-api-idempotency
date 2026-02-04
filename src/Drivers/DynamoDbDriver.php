<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Drivers;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use DateTimeImmutable;
use Grazulex\ApiIdempotency\Contracts\StorageDriverInterface;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;

class DynamoDbDriver implements StorageDriverInterface
{
    protected ?DynamoDbClient $client = null;

    protected ?Marshaler $marshaler = null;

    protected string $table;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected int $ttl,
    ) {
        $this->table = $config['table'] ?? 'idempotency_keys';
    }

    public function get(string $key, ?string $scope = null): ?IdempotencyRecord
    {
        $client = $this->getClient();
        $marshaler = $this->getMarshaler();

        $compositeKey = $this->buildCompositeKey($key, $scope);

        try {
            $result = $client->getItem([
                'TableName' => $this->table,
                'Key' => $marshaler->marshalItem([
                    'pk' => $compositeKey,
                ]),
            ]);

            if (! isset($result['Item'])) {
                return null;
            }

            $item = $marshaler->unmarshalItem($result['Item']);

            // Check if expired
            if (isset($item['expires_at']) && $item['expires_at'] < time()) {
                return null;
            }

            return $this->itemToRecord($item);
        } catch (\Exception) {
            return null;
        }
    }

    public function store(string $key, IdempotencyRecord $record, ?string $scope = null): bool
    {
        $client = $this->getClient();
        $marshaler = $this->getMarshaler();

        $compositeKey = $this->buildCompositeKey($key, $scope);

        try {
            $client->putItem([
                'TableName' => $this->table,
                'Item' => $marshaler->marshalItem([
                    'pk' => $compositeKey,
                    'key' => $key,
                    'scope' => $scope,
                    'fingerprint' => $record->fingerprint,
                    'method' => $record->method,
                    'path' => $record->path,
                    'status_code' => $record->statusCode,
                    'response_headers' => $record->responseHeaders,
                    'response_body' => $record->responseBody,
                    'response_size' => $record->responseSize,
                    'state' => $record->state,
                    'created_at' => $record->createdAt->getTimestamp(),
                    'expires_at' => $record->expiresAt->getTimestamp(),
                    'locked_until' => $record->lockedUntil?->getTimestamp(),
                    'ttl' => $record->expiresAt->getTimestamp(), // DynamoDB TTL attribute
                ]),
            ]);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function lock(string $key, ?string $scope = null, int $timeout = 10): bool
    {
        $client = $this->getClient();
        $marshaler = $this->getMarshaler();

        $compositeKey = $this->buildCompositeKey($key, $scope);
        $lockedUntil = time() + $timeout;

        try {
            $client->putItem([
                'TableName' => $this->table,
                'Item' => $marshaler->marshalItem([
                    'pk' => $compositeKey,
                    'key' => $key,
                    'scope' => $scope,
                    'state' => 'processing',
                    'locked_until' => $lockedUntil,
                    'created_at' => time(),
                    'expires_at' => time() + $this->ttl,
                    'ttl' => time() + $this->ttl,
                ]),
                'ConditionExpression' => 'attribute_not_exists(pk) OR locked_until < :now',
                'ExpressionAttributeValues' => $marshaler->marshalItem([
                    ':now' => time(),
                ]),
            ]);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function unlock(string $key, ?string $scope = null): bool
    {
        $client = $this->getClient();
        $marshaler = $this->getMarshaler();

        $compositeKey = $this->buildCompositeKey($key, $scope);

        try {
            $client->updateItem([
                'TableName' => $this->table,
                'Key' => $marshaler->marshalItem([
                    'pk' => $compositeKey,
                ]),
                'UpdateExpression' => 'REMOVE locked_until',
            ]);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function isLocked(string $key, ?string $scope = null): bool
    {
        $record = $this->get($key, $scope);

        if ($record === null) {
            return false;
        }

        return $record->isProcessing() &&
               $record->lockedUntil !== null &&
               $record->lockedUntil > new DateTimeImmutable;
    }

    public function forget(string $key, ?string $scope = null): bool
    {
        $client = $this->getClient();
        $marshaler = $this->getMarshaler();

        $compositeKey = $this->buildCompositeKey($key, $scope);

        try {
            $client->deleteItem([
                'TableName' => $this->table,
                'Key' => $marshaler->marshalItem([
                    'pk' => $compositeKey,
                ]),
            ]);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function cleanup(): int
    {
        // DynamoDB handles TTL-based cleanup automatically
        return 0;
    }

    /**
     * @return array{total: int, hits: int, size: int}
     */
    public function stats(): array
    {
        // DynamoDB scan is expensive, return empty stats
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
        // DynamoDB scan is expensive, return empty list
        return [];
    }

    protected function buildCompositeKey(string $key, ?string $scope = null): string
    {
        return $scope !== null ? "{$scope}#{$key}" : $key;
    }

    protected function getClient(): DynamoDbClient
    {
        if ($this->client === null) {
            $this->client = new DynamoDbClient([
                'region' => $this->config['region'] ?? 'eu-west-1',
                'version' => 'latest',
            ]);
        }

        return $this->client;
    }

    protected function getMarshaler(): Marshaler
    {
        if ($this->marshaler === null) {
            $this->marshaler = new Marshaler;
        }

        return $this->marshaler;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function itemToRecord(array $item): IdempotencyRecord
    {
        return new IdempotencyRecord(
            key: $item['key'],
            scope: $item['scope'] ?? null,
            fingerprint: $item['fingerprint'] ?? null,
            method: $item['method'] ?? '',
            path: $item['path'] ?? '',
            statusCode: (int) ($item['status_code'] ?? 0),
            responseHeaders: $item['response_headers'] ?? null,
            responseBody: $item['response_body'] ?? null,
            responseSize: (int) ($item['response_size'] ?? 0),
            state: $item['state'] ?? 'processing',
            createdAt: (new DateTimeImmutable)->setTimestamp((int) $item['created_at']),
            expiresAt: (new DateTimeImmutable)->setTimestamp((int) $item['expires_at']),
            lockedUntil: isset($item['locked_until'])
                ? (new DateTimeImmutable)->setTimestamp((int) $item['locked_until'])
                : null,
        );
    }
}
