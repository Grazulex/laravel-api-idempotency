<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Drivers\CacheDriver;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;
use Illuminate\Cache\CacheManager;

beforeEach(function () {
    $this->cacheManager = app(CacheManager::class);
    $this->driver = new CacheDriver(
        $this->cacheManager,
        ['store' => 'array', 'prefix' => 'test_idempotency:'],
        3600
    );
});

it('stores and retrieves a record', function () {
    $record = createTestRecord('test_key_123');

    $stored = $this->driver->store('test_key_123', $record);
    expect($stored)->toBeTrue();

    $retrieved = $this->driver->get('test_key_123');
    expect($retrieved)->not->toBeNull();
    expect($retrieved->key)->toBe('test_key_123');
    expect($retrieved->statusCode)->toBe(200);
});

it('returns null for non-existent key', function () {
    $retrieved = $this->driver->get('non_existent_key');
    expect($retrieved)->toBeNull();
});

it('stores and retrieves a record with scope', function () {
    $record = createTestRecord('scoped_key_123');

    $this->driver->store('scoped_key_123', $record, 'user_42');

    $withoutScope = $this->driver->get('scoped_key_123');
    expect($withoutScope)->toBeNull();

    $withScope = $this->driver->get('scoped_key_123', 'user_42');
    expect($withScope)->not->toBeNull();
    expect($withScope->key)->toBe('scoped_key_123');
});

it('forgets a key', function () {
    $record = createTestRecord('forget_key_123');
    $this->driver->store('forget_key_123', $record);

    expect($this->driver->get('forget_key_123'))->not->toBeNull();

    $forgotten = $this->driver->forget('forget_key_123');
    expect($forgotten)->toBeTrue();

    expect($this->driver->get('forget_key_123'))->toBeNull();
});

it('locks a key', function () {
    $locked = $this->driver->lock('lock_key_123');
    expect($locked)->toBeTrue();

    expect($this->driver->isLocked('lock_key_123'))->toBeTrue();
});

it('prevents double locking', function () {
    $this->driver->lock('double_lock_key');
    $secondLock = $this->driver->lock('double_lock_key');

    expect($secondLock)->toBeFalse();
});

it('unlocks a key', function () {
    $this->driver->lock('unlock_key_123');
    expect($this->driver->isLocked('unlock_key_123'))->toBeTrue();

    $unlocked = $this->driver->unlock('unlock_key_123');
    expect($unlocked)->toBeTrue();

    expect($this->driver->isLocked('unlock_key_123'))->toBeFalse();
});

it('returns stats', function () {
    $stats = $this->driver->stats();

    expect($stats)->toBeArray();
    expect($stats)->toHaveKeys(['total', 'hits', 'size']);
});

it('returns empty list for cache driver', function () {
    $list = $this->driver->list();

    expect($list)->toBeArray();
    expect($list)->toBeEmpty();
});

it('cleanup returns zero for cache driver', function () {
    $count = $this->driver->cleanup();

    expect($count)->toBe(0);
});

it('handles scope in lock operations', function () {
    $this->driver->lock('scoped_lock', 'scope_a');

    expect($this->driver->isLocked('scoped_lock', 'scope_a'))->toBeTrue();
    expect($this->driver->isLocked('scoped_lock', 'scope_b'))->toBeFalse();

    $this->driver->unlock('scoped_lock', 'scope_a');
    expect($this->driver->isLocked('scoped_lock', 'scope_a'))->toBeFalse();
});

function createTestRecord(string $key, string $state = 'completed'): IdempotencyRecord
{
    return new IdempotencyRecord(
        key: $key,
        scope: null,
        fingerprint: 'fp_'.md5($key),
        method: 'POST',
        path: '/api/test',
        statusCode: 200,
        responseHeaders: ['Content-Type' => ['application/json']],
        responseBody: '{"success": true}',
        responseSize: 17,
        state: $state,
        createdAt: new DateTimeImmutable,
        expiresAt: new DateTimeImmutable('+1 day'),
    );
}
