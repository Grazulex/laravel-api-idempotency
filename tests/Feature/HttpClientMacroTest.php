<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Http\Client\IdempotentRequest;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

it('registers the withIdempotencyKey macro on the http client', function () {
    expect(PendingRequest::hasMacro('withIdempotencyKey'))->toBeTrue();
});

it('sends the given idempotency key', function () {
    Http::fake(['*' => Http::response(['ok' => true], 201)]);

    Http::withIdempotencyKey('pay_order_12345')->post('https://api.example.com/payments', ['amount' => 100]);

    Http::assertSent(fn (Request $request): bool => $request->header('Idempotency-Key') === ['pay_order_12345']);
});

it('generates a key when none is given', function () {
    Http::fake(['*' => Http::response([], 201)]);

    Http::withIdempotencyKey()->post('https://api.example.com/payments');

    Http::assertSent(function (Request $request): bool {
        $key = $request->header('Idempotency-Key')[0] ?? '';

        return str_starts_with($key, 'idem_') && strlen($key) > 10;
    });
});

it('uses the given prefix for generated keys', function () {
    Http::fake(['*' => Http::response([], 201)]);

    Http::withIdempotencyKey(prefix: 'pay')->post('https://api.example.com/payments');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('Idempotency-Key')[0] ?? '', 'pay_'));
});

it('uses the configured header name', function () {
    config()->set('api-idempotency.header', 'X-Idempotency-Key');
    Http::fake(['*' => Http::response([], 201)]);

    Http::withIdempotencyKey('custom_header_key')->post('https://api.example.com/payments');

    Http::assertSent(fn (Request $request): bool => $request->header('X-Idempotency-Key') === ['custom_header_key']
        && ! $request->hasHeader('Idempotency-Key'));
});

it('can be chained after other pending request methods', function () {
    Http::fake(['*' => Http::response([], 201)]);

    Http::withToken('secret')
        ->withIdempotencyKey('chained_key_12345')
        ->post('https://api.example.com/payments');

    Http::assertSent(fn (Request $request): bool => $request->header('Idempotency-Key') === ['chained_key_12345']
        && $request->header('Authorization') === ['Bearer secret']);
});

it('does not retry by default', function () {
    Http::fake(['*' => Http::response([], 503)]);

    $response = Http::withIdempotencyKey('no_retry_key_123')->post('https://api.example.com/payments');

    expect($response->status())->toBe(503);
    Http::assertSentCount(1);
});

it('retries server errors with the same key', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push([], 503)
            ->push([], 502)
            ->push(['id' => 1], 201),
    ]);

    $response = Http::withIdempotencyKey(retries: 2, sleepMilliseconds: 0)
        ->post('https://api.example.com/payments', ['amount' => 100]);

    expect($response->status())->toBe(201);
    Http::assertSentCount(3);

    $keys = Http::recorded()
        ->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])
        ->unique();

    expect($keys)->toHaveCount(1);
});

it('retries conflicts', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push([], 409)
            ->push([], 201),
    ]);

    $response = Http::withIdempotencyKey('conflict_key_123', retries: 1, sleepMilliseconds: 0)
        ->post('https://api.example.com/payments');

    expect($response->status())->toBe(201);
    Http::assertSentCount(2);
});

it('does not retry client errors', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push([], 422)
            ->push([], 201),
    ]);

    $response = Http::withIdempotencyKey('mismatch_key_123', retries: 3, sleepMilliseconds: 0)
        ->post('https://api.example.com/payments');

    expect($response->status())->toBe(422);
    Http::assertSentCount(1);
});

it('returns the last response without throwing when retries are exhausted', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $response = Http::withIdempotencyKey('exhausted_key_123', retries: 2, sleepMilliseconds: 0)
        ->post('https://api.example.com/payments');

    expect($response->status())->toBe(500);
    Http::assertSentCount(3);
});

it('decides which failures are retryable', function () {
    $requestException = fn (int $status): RequestException => new RequestException(new Response(new Psr7Response($status)));

    expect(IdempotentRequest::shouldRetry(new ConnectionException('timeout')))->toBeTrue()
        ->and(IdempotentRequest::shouldRetry($requestException(500)))->toBeTrue()
        ->and(IdempotentRequest::shouldRetry($requestException(503)))->toBeTrue()
        ->and(IdempotentRequest::shouldRetry($requestException(409)))->toBeTrue()
        ->and(IdempotentRequest::shouldRetry($requestException(429)))->toBeTrue()
        ->and(IdempotentRequest::shouldRetry($requestException(400)))->toBeFalse()
        ->and(IdempotentRequest::shouldRetry($requestException(422)))->toBeFalse()
        ->and(IdempotentRequest::shouldRetry(new RuntimeException('other')))->toBeFalse()
        ->and(IdempotentRequest::shouldRetry(null))->toBeFalse();
});
