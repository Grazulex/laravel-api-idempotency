<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Events\IdempotentPayloadMismatch;
use Grazulex\ApiIdempotency\Events\IdempotentRequestProcessed;
use Grazulex\ApiIdempotency\Events\IdempotentRequestReplayed;
use Grazulex\ApiIdempotency\Exceptions\InvalidKeyException;
use Grazulex\ApiIdempotency\Exceptions\MissingKeyException;
use Grazulex\ApiIdempotency\Exceptions\PayloadMismatchException;
use Grazulex\ApiIdempotency\Http\Middleware\IdempotentMiddleware;
use Grazulex\ApiIdempotency\IdempotencyManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config(['api-idempotency.enabled' => true]);
    config(['api-idempotency.key.required' => false]);
    config(['api-idempotency.fingerprint.enabled' => true]);
    config(['api-idempotency.scope.enabled' => false]);
});

it('passes through requests without idempotency key when not required', function () {
    $request = Request::create('/api/test', 'POST');
    $middleware = app(IdempotentMiddleware::class);

    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('OK');
});

it('passes through GET requests', function () {
    $request = Request::create('/api/test', 'GET');
    $request->headers->set('Idempotency-Key', 'test_key_12345');
    $middleware = app(IdempotentMiddleware::class);

    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->has('Idempotency-Key'))->toBeFalse();
});

it('adds response headers for idempotent requests', function () {
    $request = Request::create('/api/test', 'POST');
    $request->headers->set('Idempotency-Key', 'test_key_12345');
    $middleware = app(IdempotentMiddleware::class);

    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    expect($response->headers->get('Idempotency-Key'))->toBe('test_key_12345');
    expect($response->headers->get('X-Idempotent-Replayed'))->toBe('false');
});

it('rejects invalid key format', function () {
    $request = Request::create('/api/test', 'POST');
    $request->headers->set('Idempotency-Key', 'short');
    $middleware = app(IdempotentMiddleware::class);

    $middleware->handle($request, fn () => new Response('OK', 200));
})->throws(InvalidKeyException::class);

it('requires key when configured', function () {
    config(['api-idempotency.key.required' => true]);

    $request = Request::create('/api/test', 'POST');
    $middleware = app(IdempotentMiddleware::class);

    $middleware->handle($request, fn () => new Response('OK', 200));
})->throws(MissingKeyException::class);

it('passes through when disabled', function () {
    config(['api-idempotency.enabled' => false]);

    $request = Request::create('/api/test', 'POST');
    $request->headers->set('Idempotency-Key', 'test_key_12345');
    $middleware = app(IdempotentMiddleware::class);

    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->has('Idempotency-Key'))->toBeFalse();
});

// Advanced Middleware Tests

it('stores response and dispatches event on first request', function () {
    Event::fake([IdempotentRequestProcessed::class]);

    $request = Request::create('/api/payments', 'POST', ['amount' => 100]);
    $request->headers->set('Idempotency-Key', 'payment_key_12345');
    $middleware = app(IdempotentMiddleware::class);

    $response = $middleware->handle($request, fn () => new Response('{"id": 1}', 201));

    expect($response->getStatusCode())->toBe(201);
    expect($response->headers->get('X-Idempotent-Replayed'))->toBe('false');

    Event::assertDispatched(IdempotentRequestProcessed::class, function ($event) {
        return $event->key === 'payment_key_12345';
    });
});

it('replays cached response on second request', function () {
    Event::fake([IdempotentRequestProcessed::class, IdempotentRequestReplayed::class]);

    $middleware = app(IdempotentMiddleware::class);
    $key = 'replay_test_key_123';

    // First request
    $request1 = Request::create('/api/test', 'POST', ['data' => 'test']);
    $request1->headers->set('Idempotency-Key', $key);
    $response1 = $middleware->handle($request1, fn () => new Response('{"id": 1}', 201));

    expect($response1->getStatusCode())->toBe(201);

    // Second request with same key
    $request2 = Request::create('/api/test', 'POST', ['data' => 'test']);
    $request2->headers->set('Idempotency-Key', $key);
    $response2 = $middleware->handle($request2, fn () => new Response('{"id": 2}', 201));

    expect($response2->getStatusCode())->toBe(201);
    expect($response2->getContent())->toBe('{"id": 1}'); // Should be cached response
    expect($response2->headers->get('X-Idempotent-Replayed'))->toBe('true');

    Event::assertDispatched(IdempotentRequestReplayed::class);
});

it('detects payload mismatch on key reuse', function () {
    Event::fake([IdempotentPayloadMismatch::class]);
    config(['api-idempotency.fingerprint.enabled' => true]);

    $middleware = app(IdempotentMiddleware::class);
    $key = 'mismatch_test_key_123';

    // First request with original payload
    $request1 = Request::create('/api/payments', 'POST', ['amount' => 100]);
    $request1->headers->set('Idempotency-Key', $key);
    $middleware->handle($request1, fn () => new Response('{"id": 1}', 201));

    // Second request with different payload
    $request2 = Request::create('/api/payments', 'POST', ['amount' => 200]);
    $request2->headers->set('Idempotency-Key', $key);

    expect(fn () => $middleware->handle($request2, fn () => new Response('{"id": 2}', 201)))
        ->toThrow(PayloadMismatchException::class);

    Event::assertDispatched(IdempotentPayloadMismatch::class);
});

it('handles middleware options for ttl', function () {
    $request = Request::create('/api/test', 'POST');
    $request->headers->set('Idempotency-Key', 'ttl_option_key_123');
    $middleware = app(IdempotentMiddleware::class);

    $response = $middleware->handle($request, fn () => new Response('OK', 200), 'ttl=7200');

    expect($response->getStatusCode())->toBe(200);
});

it('handles middleware options for required', function () {
    $request = Request::create('/api/test', 'POST');
    $middleware = app(IdempotentMiddleware::class);

    expect(fn () => $middleware->handle($request, fn () => new Response('OK', 200), 'required'))
        ->toThrow(MissingKeyException::class);
});

it('handles PUT method when configured', function () {
    config(['api-idempotency.methods' => ['POST', 'PUT']]);

    $request = Request::create('/api/test/1', 'PUT');
    $request->headers->set('Idempotency-Key', 'put_key_12345');
    $middleware = app(IdempotentMiddleware::class);

    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    expect($response->headers->get('Idempotency-Key'))->toBe('put_key_12345');
});

it('ignores DELETE method by default', function () {
    $request = Request::create('/api/test/1', 'DELETE');
    $request->headers->set('Idempotency-Key', 'delete_key_12345');
    $middleware = app(IdempotentMiddleware::class);

    $response = $middleware->handle($request, fn () => new Response('', 204));

    expect($response->headers->has('Idempotency-Key'))->toBeFalse();
});

it('skips caching when manager skip is called', function () {
    $manager = app(IdempotencyManager::class);
    $middleware = app(IdempotentMiddleware::class);

    $request = Request::create('/api/test', 'POST');
    $request->headers->set('Idempotency-Key', 'skip_cache_key_123');

    $response = $middleware->handle($request, function () use ($manager) {
        $manager->skip();

        return new Response('OK', 200);
    });

    expect($response->getStatusCode())->toBe(200);

    // Verify the skip flag was reset
    expect($manager->shouldSkip())->toBeFalse();
});

it('stores error responses for idempotency', function () {
    $middleware = app(IdempotentMiddleware::class);
    $key = 'error_response_key_123';

    // First request returns error
    $request1 = Request::create('/api/test', 'POST');
    $request1->headers->set('Idempotency-Key', $key);
    $response1 = $middleware->handle($request1, fn () => new Response('{"error": "Bad Request"}', 400));

    expect($response1->getStatusCode())->toBe(400);

    // Second request should return cached error
    $request2 = Request::create('/api/test', 'POST');
    $request2->headers->set('Idempotency-Key', $key);
    $response2 = $middleware->handle($request2, fn () => new Response('{"success": true}', 200));

    expect($response2->getStatusCode())->toBe(400);
    expect($response2->getContent())->toBe('{"error": "Bad Request"}');
});

it('does not store 500 error responses', function () {
    $middleware = app(IdempotentMiddleware::class);
    $key = 'server_error_key_123';

    // First request returns server error
    $request1 = Request::create('/api/test', 'POST');
    $request1->headers->set('Idempotency-Key', $key);

    try {
        $middleware->handle($request1, fn () => new Response('Server Error', 500));
    } catch (Throwable) {
        // Ignore
    }

    // Second request should execute fresh (not cached)
    $request2 = Request::create('/api/test', 'POST');
    $request2->headers->set('Idempotency-Key', $key);
    $response2 = $middleware->handle($request2, fn () => new Response('{"success": true}', 200));

    expect($response2->getStatusCode())->toBe(200);
    expect($response2->getContent())->toBe('{"success": true}');
});

it('uses custom header name from config', function () {
    config(['api-idempotency.header' => 'X-Request-Id']);

    $request = Request::create('/api/test', 'POST');
    $request->headers->set('X-Request-Id', 'custom_header_key_123');
    $middleware = app(IdempotentMiddleware::class);

    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    expect($response->headers->get('X-Request-Id'))->toBe('custom_header_key_123');
});

it('accepts various valid key formats', function () {
    $middleware = app(IdempotentMiddleware::class);

    $validKeys = [
        'simple_key_123456',
        'uuid-format-key-abc',
        'MixedCase123Key',
        'key_with_numbers_12345',
        'UPPERCASE_KEY_123',
    ];

    foreach ($validKeys as $key) {
        $request = Request::create('/api/test', 'POST');
        $request->headers->set('Idempotency-Key', $key);

        $response = $middleware->handle($request, fn () => new Response('OK', 200));

        expect($response->getStatusCode())->toBe(200);
    }
});

it('unlocks key when exception occurs during processing', function () {
    $manager = app(IdempotencyManager::class);
    $middleware = app(IdempotentMiddleware::class);
    $key = 'exception_key_12345';

    $request = Request::create('/api/test', 'POST');
    $request->headers->set('Idempotency-Key', $key);

    try {
        $middleware->handle($request, function () {
            throw new RuntimeException('Test exception');
        });
    } catch (RuntimeException) {
        // Expected
    }

    // Key should be unlocked after exception
    expect($manager->isLocked($key))->toBeFalse();
});
