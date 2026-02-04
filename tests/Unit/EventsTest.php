<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Events\IdempotentConflictDetected;
use Grazulex\ApiIdempotency\Events\IdempotentPayloadMismatch;
use Grazulex\ApiIdempotency\Events\IdempotentRequestProcessed;
use Grazulex\ApiIdempotency\Events\IdempotentRequestReplayed;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('creates IdempotentRequestProcessed event', function () {
    $request = Request::create('/api/test', 'POST');
    $response = new Response('{"id": 1}', 201);

    $event = new IdempotentRequestProcessed(
        key: 'test_key_123',
        scope: 'user_42',
        request: $request,
        response: $response,
        fingerprint: 'fp_abc123',
    );

    expect($event->key)->toBe('test_key_123');
    expect($event->scope)->toBe('user_42');
    expect($event->request)->toBe($request);
    expect($event->response)->toBe($response);
    expect($event->fingerprint)->toBe('fp_abc123');
});

it('creates IdempotentRequestProcessed event with null scope', function () {
    $request = Request::create('/api/test', 'POST');
    $response = new Response('test', 200);

    $event = new IdempotentRequestProcessed(
        key: 'no_scope_key',
        scope: null,
        request: $request,
        response: $response,
        fingerprint: 'fp_123',
    );

    expect($event->scope)->toBeNull();
});

it('creates IdempotentRequestReplayed event', function () {
    $request = Request::create('/api/test', 'POST');
    $originalTime = new DateTimeImmutable('2025-01-15 10:00:00');

    $event = new IdempotentRequestReplayed(
        key: 'replay_key_123',
        scope: 'tenant_5',
        request: $request,
        originalRequestTime: $originalTime,
        hitCount: 3,
    );

    expect($event->key)->toBe('replay_key_123');
    expect($event->scope)->toBe('tenant_5');
    expect($event->request)->toBe($request);
    expect($event->originalRequestTime)->toBe($originalTime);
    expect($event->hitCount)->toBe(3);
});

it('creates IdempotentConflictDetected event', function () {
    $request = Request::create('/api/payments', 'POST');

    $event = new IdempotentConflictDetected(
        key: 'conflict_key_123',
        scope: null,
        request: $request,
        waitTimeout: 10,
    );

    expect($event->key)->toBe('conflict_key_123');
    expect($event->scope)->toBeNull();
    expect($event->request)->toBe($request);
    expect($event->waitTimeout)->toBe(10);
});

it('creates IdempotentPayloadMismatch event', function () {
    $request = Request::create('/api/orders', 'POST', ['amount' => 200]);

    $event = new IdempotentPayloadMismatch(
        key: 'mismatch_key_123',
        scope: 'user_10',
        request: $request,
        originalFingerprint: 'original_fp_abc',
        currentFingerprint: 'current_fp_xyz',
    );

    expect($event->key)->toBe('mismatch_key_123');
    expect($event->scope)->toBe('user_10');
    expect($event->request)->toBe($request);
    expect($event->originalFingerprint)->toBe('original_fp_abc');
    expect($event->currentFingerprint)->toBe('current_fp_xyz');
});

it('events use Dispatchable trait', function () {
    expect(class_uses(IdempotentRequestProcessed::class))
        ->toContain(Illuminate\Foundation\Events\Dispatchable::class);

    expect(class_uses(IdempotentRequestReplayed::class))
        ->toContain(Illuminate\Foundation\Events\Dispatchable::class);

    expect(class_uses(IdempotentConflictDetected::class))
        ->toContain(Illuminate\Foundation\Events\Dispatchable::class);

    expect(class_uses(IdempotentPayloadMismatch::class))
        ->toContain(Illuminate\Foundation\Events\Dispatchable::class);
});

it('events use SerializesModels trait', function () {
    expect(class_uses(IdempotentRequestProcessed::class))
        ->toContain(Illuminate\Queue\SerializesModels::class);

    expect(class_uses(IdempotentRequestReplayed::class))
        ->toContain(Illuminate\Queue\SerializesModels::class);

    expect(class_uses(IdempotentConflictDetected::class))
        ->toContain(Illuminate\Queue\SerializesModels::class);

    expect(class_uses(IdempotentPayloadMismatch::class))
        ->toContain(Illuminate\Queue\SerializesModels::class);
});
