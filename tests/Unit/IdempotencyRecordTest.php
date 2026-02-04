<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Support\IdempotencyRecord;

it('can create a record from array', function () {
    $data = [
        'key' => 'test_key_123',
        'scope' => 'user_1',
        'fingerprint' => 'abc123',
        'method' => 'POST',
        'path' => '/api/payments',
        'status_code' => 201,
        'response_headers' => ['Content-Type' => ['application/json']],
        'response_body' => '{"id": 1}',
        'response_size' => 9,
        'state' => 'completed',
        'created_at' => '2025-01-15T10:00:00+00:00',
        'expires_at' => '2025-01-16T10:00:00+00:00',
    ];

    $record = IdempotencyRecord::fromArray($data);

    expect($record->key)->toBe('test_key_123');
    expect($record->scope)->toBe('user_1');
    expect($record->fingerprint)->toBe('abc123');
    expect($record->method)->toBe('POST');
    expect($record->path)->toBe('/api/payments');
    expect($record->statusCode)->toBe(201);
    expect($record->responseBody)->toBe('{"id": 1}');
    expect($record->state)->toBe('completed');
});

it('can convert to array', function () {
    $record = new IdempotencyRecord(
        key: 'test_key',
        scope: null,
        fingerprint: 'fp123',
        method: 'POST',
        path: '/test',
        statusCode: 200,
        responseHeaders: null,
        responseBody: 'test',
        responseSize: 4,
        state: 'completed',
        createdAt: new DateTimeImmutable('2025-01-15T10:00:00+00:00'),
        expiresAt: new DateTimeImmutable('2025-01-16T10:00:00+00:00'),
    );

    $array = $record->toArray();

    expect($array)->toBeArray();
    expect($array['key'])->toBe('test_key');
    expect($array['state'])->toBe('completed');
});

it('can check state', function () {
    $processing = new IdempotencyRecord(
        key: 'test',
        scope: null,
        fingerprint: null,
        method: 'POST',
        path: '/',
        statusCode: 0,
        responseHeaders: null,
        responseBody: null,
        responseSize: 0,
        state: 'processing',
        createdAt: new DateTimeImmutable,
        expiresAt: new DateTimeImmutable('+1 day'),
    );

    $completed = new IdempotencyRecord(
        key: 'test',
        scope: null,
        fingerprint: null,
        method: 'POST',
        path: '/',
        statusCode: 200,
        responseHeaders: null,
        responseBody: null,
        responseSize: 0,
        state: 'completed',
        createdAt: new DateTimeImmutable,
        expiresAt: new DateTimeImmutable('+1 day'),
    );

    expect($processing->isProcessing())->toBeTrue();
    expect($processing->isCompleted())->toBeFalse();
    expect($completed->isCompleted())->toBeTrue();
    expect($completed->isProcessing())->toBeFalse();
});

it('can check expiration', function () {
    $expired = new IdempotencyRecord(
        key: 'test',
        scope: null,
        fingerprint: null,
        method: 'POST',
        path: '/',
        statusCode: 200,
        responseHeaders: null,
        responseBody: null,
        responseSize: 0,
        state: 'completed',
        createdAt: new DateTimeImmutable('-2 days'),
        expiresAt: new DateTimeImmutable('-1 day'),
    );

    $valid = new IdempotencyRecord(
        key: 'test',
        scope: null,
        fingerprint: null,
        method: 'POST',
        path: '/',
        statusCode: 200,
        responseHeaders: null,
        responseBody: null,
        responseSize: 0,
        state: 'completed',
        createdAt: new DateTimeImmutable,
        expiresAt: new DateTimeImmutable('+1 day'),
    );

    expect($expired->isExpired())->toBeTrue();
    expect($valid->isExpired())->toBeFalse();
});

it('can convert to response', function () {
    $record = new IdempotencyRecord(
        key: 'test',
        scope: null,
        fingerprint: null,
        method: 'POST',
        path: '/',
        statusCode: 201,
        responseHeaders: ['Content-Type' => ['application/json']],
        responseBody: '{"success": true}',
        responseSize: 17,
        state: 'completed',
        createdAt: new DateTimeImmutable,
        expiresAt: new DateTimeImmutable('+1 day'),
    );

    $response = $record->toResponse();

    expect($response->getStatusCode())->toBe(201);
    expect($response->getContent())->toBe('{"success": true}');
});
