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

/**
 * Send a first request answering $firstStatus, then a second one with the same key.
 */
function sendTwiceWithSameIdempotencyKey(string $key, int $firstStatus): Response
{
    $middleware = app(IdempotentMiddleware::class);

    $request1 = Request::create('/api/test', 'POST');
    $request1->headers->set('Idempotency-Key', $key);
    $response1 = $middleware->handle($request1, fn () => new Response('first', $firstStatus));

    expect($response1->getStatusCode())->toBe($firstStatus);

    $request2 = Request::create('/api/test', 'POST');
    $request2->headers->set('Idempotency-Key', $key);

    /** @var Response $response2 */
    $response2 = $middleware->handle($request2, fn () => new Response('second', 200));

    return $response2;
}

it('keeps the default 200 to 499 policy when store_statuses is null', function () {
    config(['api-idempotency.storage.store_statuses' => null]);

    expect(sendTwiceWithSameIdempotencyKey('null_policy_4xx_key', 409)->getContent())->toBe('first');
    expect(sendTwiceWithSameIdempotencyKey('null_policy_5xx_key', 503)->getContent())->toBe('second');
    expect(sendTwiceWithSameIdempotencyKey('null_policy_1xx_key', 199)->getContent())->toBe('second');
});

it('never replays 4xx responses with a 2xx only policy', function () {
    config(['api-idempotency.storage.store_statuses' => ['2xx']]);
    $manager = app(IdempotencyManager::class);
    $middleware = app(IdempotentMiddleware::class);

    // The unstored error response must release the lock right away
    $request = Request::create('/api/test', 'POST');
    $request->headers->set('Idempotency-Key', 'only_2xx_lock_key');
    $error = $middleware->handle($request, fn () => new Response('error', 422));

    expect($error->getStatusCode())->toBe(422);
    expect($manager->get('only_2xx_lock_key'))->toBeNull();
    expect($manager->isLocked('only_2xx_lock_key'))->toBeFalse();

    $response = sendTwiceWithSameIdempotencyKey('only_2xx_error_key', 422);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('second');
    expect($response->headers->get('X-Idempotent-Replayed'))->toBe('false');

    $replayed = sendTwiceWithSameIdempotencyKey('only_2xx_success_key', 201);

    expect($replayed->getStatusCode())->toBe(201);
    expect($replayed->getContent())->toBe('first');
    expect($replayed->headers->get('X-Idempotent-Replayed'))->toBe('true');
});

it('replays only the listed business errors', function () {
    config(['api-idempotency.storage.store_statuses' => ['200-299', 422, '402']]);

    expect(sendTwiceWithSameIdempotencyKey('listed_422_key', 422)->getContent())->toBe('first');
    expect(sendTwiceWithSameIdempotencyKey('listed_402_key', 402)->getContent())->toBe('first');
    expect(sendTwiceWithSameIdempotencyKey('unlisted_400_key', 400)->getContent())->toBe('second');
    expect(sendTwiceWithSameIdempotencyKey('unlisted_404_key', 404)->getContent())->toBe('second');
});

it('can replay server errors when configured', function () {
    config(['api-idempotency.storage.store_statuses' => ['2xx', '503']]);

    $response = sendTwiceWithSameIdempotencyKey('replay_503_key', 503);

    expect($response->getStatusCode())->toBe(503);
    expect($response->getContent())->toBe('first');
});

it('stores nothing with an empty store_statuses list', function () {
    config(['api-idempotency.storage.store_statuses' => []]);

    expect(sendTwiceWithSameIdempotencyKey('empty_policy_key', 201)->getContent())->toBe('second');
});

it('accepts a single status class as store_statuses', function () {
    config(['api-idempotency.storage.store_statuses' => '2XX']);

    expect(sendTwiceWithSameIdempotencyKey('scalar_policy_ok_key', 200)->getContent())->toBe('first');
    expect(sendTwiceWithSameIdempotencyKey('scalar_policy_ko_key', 400)->getContent())->toBe('second');
});

it('rejects an invalid store_statuses entry before running the request', function (mixed $entry) {
    config(['api-idempotency.storage.store_statuses' => [$entry]]);
    $manager = app(IdempotencyManager::class);
    $middleware = app(IdempotentMiddleware::class);

    $request = Request::create('/api/test', 'POST');
    $request->headers->set('Idempotency-Key', 'invalid_policy_key');

    $executed = false;

    expect(fn () => $middleware->handle($request, function () use (&$executed) {
        $executed = true;

        return new Response('OK', 200);
    }))->toThrow(InvalidArgumentException::class, 'store_statuses');

    expect($executed)->toBeFalse();
    expect($manager->isLocked('invalid_policy_key'))->toBeFalse();
})->with([
    'unknown string' => ['success'],
    'reversed range' => ['499-200'],
    'out of bounds' => [600],
    'float' => [200.0],
]);

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
