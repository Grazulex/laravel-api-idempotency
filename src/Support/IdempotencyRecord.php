<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Support;

use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Response;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
class IdempotencyRecord implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $scope,
        public readonly ?string $fingerprint,
        public readonly string $method,
        public readonly string $path,
        public readonly int $statusCode,
        public readonly ?array $responseHeaders,
        public readonly ?string $responseBody,
        public readonly int $responseSize,
        public readonly string $state,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $lockedUntil = null,
        public readonly int $hitCount = 0,
    ) {}

    public function isCompleted(): bool
    {
        return $this->state === 'completed';
    }

    public function isProcessing(): bool
    {
        return $this->state === 'processing';
    }

    public function isFailed(): bool
    {
        return $this->state === 'failed';
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable;
    }

    public function toResponse(): Response
    {
        $response = new Response(
            $this->responseBody,
            $this->statusCode,
            $this->responseHeaders ?? []
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'scope' => $this->scope,
            'fingerprint' => $this->fingerprint,
            'method' => $this->method,
            'path' => $this->path,
            'status_code' => $this->statusCode,
            'response_headers' => $this->responseHeaders,
            'response_body' => $this->responseBody,
            'response_size' => $this->responseSize,
            'state' => $this->state,
            'created_at' => $this->createdAt->format('c'),
            'expires_at' => $this->expiresAt->format('c'),
            'locked_until' => $this->lockedUntil?->format('c'),
            'hit_count' => $this->hitCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            scope: $data['scope'] ?? null,
            fingerprint: $data['fingerprint'] ?? null,
            method: $data['method'],
            path: $data['path'],
            statusCode: (int) $data['status_code'],
            responseHeaders: $data['response_headers'] ?? null,
            responseBody: $data['response_body'] ?? null,
            responseSize: (int) ($data['response_size'] ?? 0),
            state: $data['state'] ?? 'processing',
            createdAt: new DateTimeImmutable($data['created_at']),
            expiresAt: new DateTimeImmutable($data['expires_at']),
            lockedUntil: isset($data['locked_until']) ? new DateTimeImmutable($data['locked_until']) : null,
            hitCount: (int) ($data['hit_count'] ?? 0),
        );
    }
}
