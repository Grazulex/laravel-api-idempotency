<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Http\Middleware\IdempotentMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
})->throws(\Grazulex\ApiIdempotency\Exceptions\InvalidKeyException::class);

it('requires key when configured', function () {
    config(['api-idempotency.key.required' => true]);

    $request = Request::create('/api/test', 'POST');
    $middleware = app(IdempotentMiddleware::class);

    $middleware->handle($request, fn () => new Response('OK', 200));
})->throws(\Grazulex\ApiIdempotency\Exceptions\MissingKeyException::class);

it('passes through when disabled', function () {
    config(['api-idempotency.enabled' => false]);

    $request = Request::create('/api/test', 'POST');
    $request->headers->set('Idempotency-Key', 'test_key_12345');
    $middleware = app(IdempotentMiddleware::class);

    $response = $middleware->handle($request, fn () => new Response('OK', 200));

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->has('Idempotency-Key'))->toBeFalse();
});
