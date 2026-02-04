<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Contracts\StorageDriverInterface;
use Grazulex\ApiIdempotency\IdempotencyManager;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

beforeEach(function () {
    $this->driver = Mockery::mock(StorageDriverInterface::class);
    $this->config = [
        'enabled' => true,
        'header' => 'Idempotency-Key',
        'key' => ['required' => false],
        'methods' => ['POST'],
        'ttl' => 86400,
        'conflict' => ['wait_timeout' => 10],
        'storage' => [
            'max_body_size' => 1048576,
            'exclude_headers' => ['Set-Cookie'],
        ],
    ];
    $this->manager = new IdempotencyManager($this->driver, $this->config);
});

afterEach(function () {
    Mockery::close();
});

it('gets a record from driver', function () {
    $record = createManagerTestRecord('test_key');

    $this->driver->shouldReceive('get')
        ->once()
        ->with('test_key', null)
        ->andReturn($record);

    $result = $this->manager->get('test_key');

    expect($result)->toBe($record);
});

it('gets a record with scope', function () {
    $record = createManagerTestRecord('test_key');

    $this->driver->shouldReceive('get')
        ->once()
        ->with('test_key', 'user_123')
        ->andReturn($record);

    $result = $this->manager->get('test_key', 'user_123');

    expect($result)->toBe($record);
});

it('stores a response', function () {
    $response = new Response('{"id": 1}', 201, ['Content-Type' => 'application/json']);
    $request = Request::create('/api/test', 'POST');

    $this->driver->shouldReceive('store')
        ->once()
        ->andReturn(true);

    $result = $this->manager->store('store_key', $response, null, 'fingerprint123', $request);

    expect($result)->toBeTrue();
});

it('excludes headers from storage', function () {
    $response = new Response('test', 200, [
        'Content-Type' => 'application/json',
        'Set-Cookie' => 'session=abc123',
    ]);

    $this->driver->shouldReceive('store')
        ->once()
        ->withArgs(function ($key, $record) {
            return ! array_key_exists('set-cookie', $record->responseHeaders ?? []);
        })
        ->andReturn(true);

    $this->manager->store('exclude_headers_key', $response);
});

it('locks a key', function () {
    $this->driver->shouldReceive('lock')
        ->once()
        ->with('lock_key', null, 10)
        ->andReturn(true);

    $result = $this->manager->lock('lock_key');

    expect($result)->toBeTrue();
});

it('unlocks a key', function () {
    $this->driver->shouldReceive('unlock')
        ->once()
        ->with('unlock_key', null)
        ->andReturn(true);

    $result = $this->manager->unlock('unlock_key');

    expect($result)->toBeTrue();
});

it('checks if key is locked', function () {
    $this->driver->shouldReceive('isLocked')
        ->once()
        ->with('check_lock_key', null)
        ->andReturn(true);

    $result = $this->manager->isLocked('check_lock_key');

    expect($result)->toBeTrue();
});

it('forgets a key', function () {
    $this->driver->shouldReceive('forget')
        ->once()
        ->with('forget_key', null)
        ->andReturn(true);

    $result = $this->manager->forget('forget_key');

    expect($result)->toBeTrue();
});

it('skips caching when requested', function () {
    expect($this->manager->shouldSkip())->toBeFalse();

    $this->manager->skip();

    expect($this->manager->shouldSkip())->toBeTrue();

    $this->manager->resetSkip();

    expect($this->manager->shouldSkip())->toBeFalse();
});

it('returns stats from driver', function () {
    $stats = ['total' => 10, 'hits' => 5, 'size' => 1024];

    $this->driver->shouldReceive('stats')
        ->once()
        ->andReturn($stats);

    $result = $this->manager->stats();

    expect($result)->toBe($stats);
});

it('lists records from driver', function () {
    $records = [createManagerTestRecord('key1'), createManagerTestRecord('key2')];

    $this->driver->shouldReceive('list')
        ->once()
        ->with(10)
        ->andReturn($records);

    $result = $this->manager->list(10);

    expect($result)->toBe($records);
});

it('cleans up via driver', function () {
    $this->driver->shouldReceive('cleanup')
        ->once()
        ->andReturn(5);

    $result = $this->manager->cleanup();

    expect($result)->toBe(5);
});

it('returns enabled status from config', function () {
    expect($this->manager->isEnabled())->toBeTrue();

    $disabledManager = new IdempotencyManager($this->driver, ['enabled' => false]);
    expect($disabledManager->isEnabled())->toBeFalse();
});

it('returns header name from config', function () {
    expect($this->manager->getHeaderName())->toBe('Idempotency-Key');

    $customManager = new IdempotencyManager($this->driver, ['header' => 'X-Custom-Key']);
    expect($customManager->getHeaderName())->toBe('X-Custom-Key');
});

it('returns key required status from config', function () {
    expect($this->manager->isKeyRequired())->toBeFalse();

    $requiredManager = new IdempotencyManager($this->driver, ['key' => ['required' => true]]);
    expect($requiredManager->isKeyRequired())->toBeTrue();
});

it('returns methods from config', function () {
    expect($this->manager->getMethods())->toBe(['POST']);

    $multiMethodManager = new IdempotencyManager($this->driver, ['methods' => ['POST', 'PUT']]);
    expect($multiMethodManager->getMethods())->toBe(['POST', 'PUT']);
});

it('returns the driver', function () {
    expect($this->manager->getDriver())->toBe($this->driver);
});

it('gets nested config values', function () {
    expect($this->manager->getConfig('conflict.wait_timeout'))->toBe(10);
    expect($this->manager->getConfig('non.existent', 'default'))->toBe('default');
});

it('does not store response body exceeding max size', function () {
    $largeBody = str_repeat('x', 2 * 1024 * 1024); // 2MB
    $response = new Response($largeBody, 200);

    $this->driver->shouldReceive('store')
        ->once()
        ->withArgs(function ($key, $record) {
            return $record->responseBody === null && $record->responseSize === 0;
        })
        ->andReturn(true);

    $this->manager->store('large_body_key', $response);
});

function createManagerTestRecord(string $key): IdempotencyRecord
{
    return new IdempotencyRecord(
        key: $key,
        scope: null,
        fingerprint: 'fp_test',
        method: 'POST',
        path: '/api/test',
        statusCode: 200,
        responseHeaders: ['Content-Type' => ['application/json']],
        responseBody: '{"success": true}',
        responseSize: 17,
        state: 'completed',
        createdAt: new DateTimeImmutable,
        expiresAt: new DateTimeImmutable('+1 day'),
    );
}
