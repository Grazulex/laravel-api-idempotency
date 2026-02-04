<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Drivers\DatabaseDriver;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Create the idempotency_keys table
    if (! Schema::hasTable('idempotency_keys')) {
        Schema::create('idempotency_keys', function ($table) {
            $table->id();
            $table->string('key', 255);
            $table->string('scope', 255)->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->integer('status_code');
            $table->json('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->integer('response_size')->default(0);
            $table->string('state', 20)->default('processing');
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->unique(['key', 'scope']);
        });
    }

    $this->databaseManager = app(DatabaseManager::class);
    $this->driver = new DatabaseDriver(
        $this->databaseManager,
        ['connection' => 'testing', 'table' => 'idempotency_keys'],
        3600
    );
});

afterEach(function () {
    // Clean up
    $this->databaseManager->table('idempotency_keys')->truncate();
});

it('stores and retrieves a record', function () {
    $record = createDbTestRecord('db_test_key_123');

    $stored = $this->driver->store('db_test_key_123', $record);
    expect($stored)->toBeTrue();

    $retrieved = $this->driver->get('db_test_key_123');
    expect($retrieved)->not->toBeNull();
    expect($retrieved->key)->toBe('db_test_key_123');
    expect($retrieved->statusCode)->toBe(200);
    expect($retrieved->state)->toBe('completed');
});

it('returns null for non-existent key', function () {
    $retrieved = $this->driver->get('non_existent_db_key');
    expect($retrieved)->toBeNull();
});

it('stores and retrieves a record with scope', function () {
    $record = createDbTestRecord('scoped_db_key');

    $this->driver->store('scoped_db_key', $record, 'user_42');

    $withoutScope = $this->driver->get('scoped_db_key');
    expect($withoutScope)->toBeNull();

    $withScope = $this->driver->get('scoped_db_key', 'user_42');
    expect($withScope)->not->toBeNull();
    expect($withScope->key)->toBe('scoped_db_key');
});

it('updates existing record', function () {
    $record1 = createDbTestRecord('update_db_key', 'processing');
    $this->driver->store('update_db_key', $record1);

    $record2 = createDbTestRecord('update_db_key', 'completed');
    $this->driver->store('update_db_key', $record2);

    $retrieved = $this->driver->get('update_db_key');
    expect($retrieved->state)->toBe('completed');
});

it('forgets a key', function () {
    $record = createDbTestRecord('forget_db_key');
    $this->driver->store('forget_db_key', $record);

    expect($this->driver->get('forget_db_key'))->not->toBeNull();

    $forgotten = $this->driver->forget('forget_db_key');
    expect($forgotten)->toBeTrue();

    expect($this->driver->get('forget_db_key'))->toBeNull();
});

it('forgets a key with scope', function () {
    $record = createDbTestRecord('scoped_forget_key');
    $this->driver->store('scoped_forget_key', $record, 'scope_a');

    $this->driver->forget('scoped_forget_key', 'scope_a');

    expect($this->driver->get('scoped_forget_key', 'scope_a'))->toBeNull();
});

it('locks a key by creating processing record', function () {
    $locked = $this->driver->lock('db_lock_key');
    expect($locked)->toBeTrue();

    expect($this->driver->isLocked('db_lock_key'))->toBeTrue();
});

it('prevents double locking', function () {
    $this->driver->lock('db_double_lock_key');
    $secondLock = $this->driver->lock('db_double_lock_key');

    expect($secondLock)->toBeFalse();
});

it('unlocks a key', function () {
    $this->driver->lock('db_unlock_key');
    expect($this->driver->isLocked('db_unlock_key'))->toBeTrue();

    $unlocked = $this->driver->unlock('db_unlock_key');
    expect($unlocked)->toBeTrue();

    expect($this->driver->isLocked('db_unlock_key'))->toBeFalse();
});

it('handles scope in lock operations', function () {
    $this->driver->lock('scoped_db_lock', 'scope_a');

    expect($this->driver->isLocked('scoped_db_lock', 'scope_a'))->toBeTrue();
    expect($this->driver->isLocked('scoped_db_lock', 'scope_b'))->toBeFalse();
});

it('cleans up expired records', function () {
    // Insert an expired record directly
    $this->databaseManager->table('idempotency_keys')->insert([
        'key' => 'expired_key',
        'method' => 'POST',
        'path' => '/test',
        'status_code' => 200,
        'state' => 'completed',
        'created_at' => now()->subDays(2),
        'expires_at' => now()->subDay(),
    ]);

    // Insert a valid record
    $validRecord = createDbTestRecord('valid_key');
    $this->driver->store('valid_key', $validRecord);

    $count = $this->driver->cleanup();

    expect($count)->toBe(1);
    expect($this->driver->get('valid_key'))->not->toBeNull();
});

it('returns stats', function () {
    $record1 = createDbTestRecord('stats_key_1');
    $record2 = createDbTestRecord('stats_key_2');
    $this->driver->store('stats_key_1', $record1);
    $this->driver->store('stats_key_2', $record2);

    $stats = $this->driver->stats();

    expect($stats)->toBeArray();
    expect($stats['total'])->toBe(2);
    expect($stats['size'])->toBeGreaterThan(0);
});

it('lists recent records', function () {
    $record1 = createDbTestRecord('list_key_1');
    $record2 = createDbTestRecord('list_key_2');
    $record3 = createDbTestRecord('list_key_3');
    $this->driver->store('list_key_1', $record1);
    $this->driver->store('list_key_2', $record2);
    $this->driver->store('list_key_3', $record3);

    $list = $this->driver->list(2);

    expect($list)->toHaveCount(2);
});

it('does not return expired records in get', function () {
    // Insert an expired record directly
    $this->databaseManager->table('idempotency_keys')->insert([
        'key' => 'expired_get_key',
        'method' => 'POST',
        'path' => '/test',
        'status_code' => 200,
        'state' => 'completed',
        'created_at' => now()->subDays(2),
        'expires_at' => now()->subDay(),
    ]);

    $record = $this->driver->get('expired_get_key');

    expect($record)->toBeNull();
});

it('preserves response headers as JSON', function () {
    $headers = ['Content-Type' => ['application/json'], 'X-Custom' => ['value']];
    $record = new IdempotencyRecord(
        key: 'headers_key',
        scope: null,
        fingerprint: 'fp_test',
        method: 'POST',
        path: '/api/test',
        statusCode: 200,
        responseHeaders: $headers,
        responseBody: '{}',
        responseSize: 2,
        state: 'completed',
        createdAt: new DateTimeImmutable,
        expiresAt: new DateTimeImmutable('+1 day'),
    );

    $this->driver->store('headers_key', $record);

    $retrieved = $this->driver->get('headers_key');
    expect($retrieved->responseHeaders)->toBe($headers);
});

function createDbTestRecord(string $key, string $state = 'completed'): IdempotencyRecord
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
