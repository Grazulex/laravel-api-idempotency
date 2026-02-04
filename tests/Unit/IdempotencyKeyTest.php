<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Exceptions\InvalidKeyException;
use Grazulex\ApiIdempotency\Support\IdempotencyKey;

it('generates unique keys', function () {
    $key1 = IdempotencyKey::generate();
    $key2 = IdempotencyKey::generate();

    expect($key1)->not->toBe($key2);
    expect($key1)->toStartWith('idem_');
    expect($key2)->toStartWith('idem_');
});

it('generates keys with custom prefix', function () {
    $key = IdempotencyKey::generate('pay');

    expect($key)->toStartWith('pay_');
});

it('generates deterministic keys from data', function () {
    $data = ['user_id' => 123, 'action' => 'create_payment'];

    $key1 = IdempotencyKey::fromData($data);
    $key2 = IdempotencyKey::fromData($data);

    expect($key1)->toBe($key2);
    expect($key1)->toStartWith('idem_sha256_');
});

it('generates different keys for different data', function () {
    $key1 = IdempotencyKey::fromData(['amount' => 100]);
    $key2 = IdempotencyKey::fromData(['amount' => 200]);

    expect($key1)->not->toBe($key2);
});

it('validates key length', function () {
    IdempotencyKey::validate('short');
})->throws(InvalidKeyException::class);

it('validates key characters', function () {
    IdempotencyKey::validate('invalid key with spaces!!!');
})->throws(InvalidKeyException::class);

it('accepts valid keys', function () {
    expect(IdempotencyKey::isValid('valid_key_12345'))->toBeTrue();
    expect(IdempotencyKey::isValid('another-valid-key'))->toBeTrue();
    expect(IdempotencyKey::isValid('MixedCase123'))->toBeTrue();
});

it('rejects invalid keys', function () {
    expect(IdempotencyKey::isValid('short'))->toBeFalse();
    expect(IdempotencyKey::isValid('has spaces'))->toBeFalse();
    expect(IdempotencyKey::isValid('special@chars!'))->toBeFalse();
});
