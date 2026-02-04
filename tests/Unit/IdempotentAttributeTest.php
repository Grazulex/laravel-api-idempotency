<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Attributes\Idempotent;
use Grazulex\ApiIdempotency\Attributes\IdempotentExcept;

it('creates Idempotent attribute with defaults', function () {
    $attribute = new Idempotent;

    expect($attribute->ttl)->toBeNull();
    expect($attribute->required)->toBeFalse();
    expect($attribute->scope)->toBeNull();
});

it('creates Idempotent attribute with custom ttl', function () {
    $attribute = new Idempotent(ttl: 3600);

    expect($attribute->ttl)->toBe(3600);
});

it('creates Idempotent attribute with required flag', function () {
    $attribute = new Idempotent(required: true);

    expect($attribute->required)->toBeTrue();
});

it('creates Idempotent attribute with scope', function () {
    $attribute = new Idempotent(scope: 'user');

    expect($attribute->scope)->toBe('user');
});

it('creates Idempotent attribute with all options', function () {
    $attribute = new Idempotent(
        ttl: 7200,
        required: true,
        scope: 'tenant',
    );

    expect($attribute->ttl)->toBe(7200);
    expect($attribute->required)->toBeTrue();
    expect($attribute->scope)->toBe('tenant');
});

it('converts to middleware string with no options', function () {
    $attribute = new Idempotent;

    expect($attribute->toMiddleware())->toBe('idempotent');
});

it('converts to middleware string with ttl', function () {
    $attribute = new Idempotent(ttl: 3600);

    expect($attribute->toMiddleware())->toBe('idempotent:ttl=3600');
});

it('converts to middleware string with required', function () {
    $attribute = new Idempotent(required: true);

    expect($attribute->toMiddleware())->toBe('idempotent:required');
});

it('converts to middleware string with scope', function () {
    $attribute = new Idempotent(scope: 'user');

    expect($attribute->toMiddleware())->toBe('idempotent:scope=user');
});

it('converts to middleware string with all options', function () {
    $attribute = new Idempotent(
        ttl: 7200,
        required: true,
        scope: 'tenant',
    );

    $middleware = $attribute->toMiddleware();

    expect($middleware)->toContain('idempotent:');
    expect($middleware)->toContain('ttl=7200');
    expect($middleware)->toContain('required');
    expect($middleware)->toContain('scope=tenant');
});

it('Idempotent attribute targets class and method', function () {
    $reflection = new ReflectionClass(Idempotent::class);
    $attributes = $reflection->getAttributes(Attribute::class);

    expect($attributes)->not->toBeEmpty();

    $attribute = $attributes[0]->newInstance();
    $flags = $attribute->flags;

    expect($flags & Attribute::TARGET_CLASS)->toBe(Attribute::TARGET_CLASS);
    expect($flags & Attribute::TARGET_METHOD)->toBe(Attribute::TARGET_METHOD);
});

it('IdempotentExcept attribute targets method only', function () {
    $reflection = new ReflectionClass(IdempotentExcept::class);
    $attributes = $reflection->getAttributes(Attribute::class);

    expect($attributes)->not->toBeEmpty();

    $attribute = $attributes[0]->newInstance();

    expect($attribute->flags)->toBe(Attribute::TARGET_METHOD);
});

it('IdempotentExcept is empty marker attribute', function () {
    $attribute = new IdempotentExcept;

    // Should have no properties (it's a marker attribute)
    $reflection = new ReflectionClass($attribute);
    $properties = $reflection->getProperties();

    expect($properties)->toBeEmpty();
});
