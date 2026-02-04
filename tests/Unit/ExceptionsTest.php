<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Exceptions\ConflictException;
use Grazulex\ApiIdempotency\Exceptions\IdempotencyException;
use Grazulex\ApiIdempotency\Exceptions\InvalidKeyException;
use Grazulex\ApiIdempotency\Exceptions\MissingKeyException;
use Grazulex\ApiIdempotency\Exceptions\PayloadMismatchException;

it('creates MissingKeyException with default message', function () {
    $exception = new MissingKeyException;

    expect($exception->getMessage())->toContain('required');
    expect($exception)->toBeInstanceOf(IdempotencyException::class);
});

it('creates MissingKeyException with custom message', function () {
    $exception = new MissingKeyException('Custom message');

    expect($exception->getMessage())->toBe('Custom message');
});

it('MissingKeyException renders to JSON response', function () {
    $exception = new MissingKeyException;
    $response = $exception->render();

    expect($response->getStatusCode())->toBe(400);

    $data = json_decode($response->getContent(), true);
    expect($data['success'])->toBeFalse();
    expect($data['error']['code'])->toBe('IDEMPOTENCY_KEY_MISSING');
});

it('creates InvalidKeyException with default message', function () {
    $exception = new InvalidKeyException;

    expect($exception->getMessage())->toContain('Invalid');
    expect($exception)->toBeInstanceOf(IdempotencyException::class);
});

it('InvalidKeyException renders to JSON response', function () {
    $exception = new InvalidKeyException;
    $response = $exception->render();

    expect($response->getStatusCode())->toBe(400);

    $data = json_decode($response->getContent(), true);
    expect($data['error']['code'])->toBe('IDEMPOTENCY_KEY_INVALID');
});

it('creates ConflictException with default retry after', function () {
    $exception = new ConflictException;

    expect($exception->retryAfter)->toBe(5);
    expect($exception)->toBeInstanceOf(IdempotencyException::class);
});

it('creates ConflictException with custom retry after', function () {
    $exception = new ConflictException(retryAfter: 15);

    expect($exception->retryAfter)->toBe(15);
});

it('ConflictException renders to JSON response with Retry-After header', function () {
    $exception = new ConflictException(retryAfter: 10);
    $response = $exception->render();

    expect($response->getStatusCode())->toBe(409);
    expect($response->headers->get('Retry-After'))->toBe('10');

    $data = json_decode($response->getContent(), true);
    expect($data['error']['code'])->toBe('IDEMPOTENCY_CONFLICT');
    expect($data['error']['retry_after'])->toBe(10);
});

it('creates PayloadMismatchException with fingerprints', function () {
    $exception = new PayloadMismatchException(
        originalFingerprint: 'original_abc123',
        currentFingerprint: 'current_xyz789',
    );

    expect($exception->originalFingerprint)->toBe('original_abc123');
    expect($exception->currentFingerprint)->toBe('current_xyz789');
    expect($exception)->toBeInstanceOf(IdempotencyException::class);
});

it('PayloadMismatchException renders to JSON response with details', function () {
    $exception = new PayloadMismatchException(
        originalFingerprint: 'abcdefgh12345678',
        currentFingerprint: 'zyxwvuts98765432',
    );
    $response = $exception->render();

    expect($response->getStatusCode())->toBe(422);

    $data = json_decode($response->getContent(), true);
    expect($data['error']['code'])->toBe('IDEMPOTENCY_PAYLOAD_MISMATCH');
    expect($data['error']['details'])->toBeArray();
    expect($data['error']['details']['original_fingerprint'])->toContain('sha256:');
    expect($data['error']['details']['current_fingerprint'])->toContain('sha256:');
});

it('PayloadMismatchException renders without details when no fingerprints', function () {
    $exception = new PayloadMismatchException;
    $response = $exception->render();

    $data = json_decode($response->getContent(), true);
    expect($data['error'])->not->toHaveKey('details');
});

it('all exceptions extend IdempotencyException', function () {
    expect(new MissingKeyException)->toBeInstanceOf(IdempotencyException::class);
    expect(new InvalidKeyException)->toBeInstanceOf(IdempotencyException::class);
    expect(new ConflictException)->toBeInstanceOf(IdempotencyException::class);
    expect(new PayloadMismatchException)->toBeInstanceOf(IdempotencyException::class);
});

it('IdempotencyException extends base Exception', function () {
    $exception = new class extends IdempotencyException {};

    expect($exception)->toBeInstanceOf(Exception::class);
});
