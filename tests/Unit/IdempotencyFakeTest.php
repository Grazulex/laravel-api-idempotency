<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Testing\IdempotencyFake;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

beforeEach(function () {
    $this->fake = new IdempotencyFake;
});

it('stores a response', function () {
    $response = new Response('{"id": 1}', 201);
    $request = Request::create('/api/test', 'POST');

    $result = $this->fake->store('test_key_123', $response, null, 'fingerprint', $request);

    expect($result)->toBeTrue();
});

it('retrieves a stored response', function () {
    $response = new Response('{"id": 1}', 201);
    $this->fake->store('retrieve_key', $response);

    $record = $this->fake->get('retrieve_key');

    expect($record)->not->toBeNull();
    expect($record->key)->toBe('retrieve_key');
    expect($record->statusCode)->toBe(201);
});

it('returns null for non-existent key', function () {
    $record = $this->fake->get('non_existent_key');

    expect($record)->toBeNull();
});

it('stores with scope', function () {
    $response = new Response('test', 200);
    $this->fake->store('scoped_key', $response, 'user_42');

    expect($this->fake->get('scoped_key'))->toBeNull();
    expect($this->fake->get('scoped_key', 'user_42'))->not->toBeNull();
});

it('forgets a key', function () {
    $response = new Response('test', 200);
    $this->fake->store('forget_key', $response);

    $this->fake->forget('forget_key');

    expect($this->fake->get('forget_key'))->toBeNull();
});

it('handles skip flag', function () {
    expect($this->fake->shouldSkip())->toBeFalse();

    $this->fake->skip();
    expect($this->fake->shouldSkip())->toBeTrue();

    $this->fake->resetSkip();
    expect($this->fake->shouldSkip())->toBeFalse();
});

it('returns stats', function () {
    $response = new Response('{"data": true}', 200);
    $this->fake->store('stats_key_1', $response);
    $this->fake->store('stats_key_2', $response);

    $stats = $this->fake->stats();

    expect($stats['total'])->toBe(2);
    expect($stats['size'])->toBeGreaterThan(0);
});

it('lists stored records', function () {
    $response = new Response('test', 200);
    $this->fake->store('list_key_1', $response);
    $this->fake->store('list_key_2', $response);
    $this->fake->store('list_key_3', $response);

    $list = $this->fake->list(2);

    expect($list)->toHaveCount(2);
});

it('cleans up all records', function () {
    $response = new Response('test', 200);
    $this->fake->store('cleanup_key_1', $response);
    $this->fake->store('cleanup_key_2', $response);

    $count = $this->fake->cleanup();

    expect($count)->toBe(2);
    expect($this->fake->get('cleanup_key_1'))->toBeNull();
});

it('records replays', function () {
    $this->fake->recordReplay('replay_key');
    $this->fake->recordReplay('replay_key');

    $stats = $this->fake->stats();
    expect($stats['hits'])->toBe(2);
});

// Assertion tests
it('asserts stored key', function () {
    $response = new Response('test', 200);
    $this->fake->store('assert_stored_key', $response);

    $this->fake->assertStored('assert_stored_key');
});

it('asserts not stored key', function () {
    $this->fake->assertNotStored('not_stored_key');
});

it('asserts replayed key', function () {
    $this->fake->recordReplay('replayed_key');

    $this->fake->assertReplayed('replayed_key');
});

it('asserts not replayed key', function () {
    $this->fake->assertNotReplayed('not_replayed_key');
});

it('asserts stored count', function () {
    $response = new Response('test', 200);
    $this->fake->store('count_key_1', $response);
    $this->fake->store('count_key_2', $response);

    $this->fake->assertStoredCount(2);
});

it('asserts nothing stored', function () {
    $this->fake->assertNothingStored();
});

it('asserts stored with status', function () {
    $response = new Response('test', 201);
    $this->fake->store('status_key', $response);

    $this->fake->assertStoredWithStatus('status_key', 201);
});

it('fails assertion for wrong status', function () {
    $response = new Response('test', 200);
    $this->fake->store('wrong_status_key', $response);

    expect(fn () => $this->fake->assertStoredWithStatus('wrong_status_key', 404))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});

it('fails assertion when key not stored', function () {
    expect(fn () => $this->fake->assertStored('non_existent'))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});

it('handles scoped assertions', function () {
    $response = new Response('test', 200);
    $this->fake->store('scoped_assert_key', $response, 'scope_a');

    $this->fake->assertStored('scoped_assert_key', 'scope_a');
    $this->fake->assertNotStored('scoped_assert_key', 'scope_b');
});
