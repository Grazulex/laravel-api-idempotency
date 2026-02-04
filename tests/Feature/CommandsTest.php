<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Contracts\StorageDriverInterface;
use Grazulex\ApiIdempotency\IdempotencyManager;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;

beforeEach(function () {
    $this->driver = Mockery::mock(StorageDriverInterface::class);
    $this->app->instance(IdempotencyManager::class, new IdempotencyManager($this->driver, config('api-idempotency')));
});

afterEach(function () {
    Mockery::close();
});

it('shows stats command output', function () {
    $this->driver->shouldReceive('stats')
        ->once()
        ->andReturn(['total' => 100, 'hits' => 50, 'size' => 102400]);

    $this->artisan('idempotency:stats')
        ->assertSuccessful();
});

it('cleans up expired records with count', function () {
    $this->driver->shouldReceive('cleanup')
        ->once()
        ->andReturn(5);

    $this->artisan('idempotency:cleanup')
        ->expectsOutput('Cleaned up 5 expired idempotency records.')
        ->assertSuccessful();
});

it('shows message when no records to cleanup', function () {
    $this->driver->shouldReceive('cleanup')
        ->once()
        ->andReturn(0);

    $this->artisan('idempotency:cleanup')
        ->expectsOutput('No expired idempotency records to clean up.')
        ->assertSuccessful();
});

it('forgets a specific key', function () {
    $this->driver->shouldReceive('forget')
        ->once()
        ->with('my_test_key_123', null)
        ->andReturn(true);

    $this->artisan('idempotency:forget', ['key' => 'my_test_key_123'])
        ->expectsOutput("Idempotency key 'my_test_key_123' has been removed.")
        ->assertSuccessful();
});

it('forgets a key with scope', function () {
    $this->driver->shouldReceive('forget')
        ->once()
        ->with('scoped_key_123', 'user_42')
        ->andReturn(true);

    $this->artisan('idempotency:forget', [
        'key' => 'scoped_key_123',
        '--scope' => 'user_42',
    ])
        ->expectsOutput("Idempotency key 'scoped_key_123' has been removed.")
        ->assertSuccessful();
});

it('shows warning when key not found on forget', function () {
    $this->driver->shouldReceive('forget')
        ->once()
        ->andReturn(false);

    $this->artisan('idempotency:forget', ['key' => 'non_existent_key'])
        ->expectsOutput("Idempotency key 'non_existent_key' was not found or could not be removed.")
        ->assertSuccessful();
});

it('lists recent records', function () {
    $records = [
        createCommandTestRecord('key_1'),
        createCommandTestRecord('key_2'),
    ];

    $this->driver->shouldReceive('list')
        ->once()
        ->with(20)
        ->andReturn($records);

    $this->artisan('idempotency:list')
        ->assertSuccessful();
});

it('lists records with custom limit', function () {
    $this->driver->shouldReceive('list')
        ->once()
        ->with(5)
        ->andReturn([]);

    $this->artisan('idempotency:list', ['--limit' => 5])
        ->expectsOutput('No idempotency records found.')
        ->assertSuccessful();
});

it('shows message when no records found', function () {
    $this->driver->shouldReceive('list')
        ->once()
        ->andReturn([]);

    $this->artisan('idempotency:list')
        ->expectsOutput('No idempotency records found.')
        ->assertSuccessful();
});

function createCommandTestRecord(string $key): IdempotencyRecord
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
