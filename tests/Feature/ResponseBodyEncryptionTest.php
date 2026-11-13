<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\Drivers\CacheDriver;
use Grazulex\ApiIdempotency\Drivers\DatabaseDriver;
use Grazulex\ApiIdempotency\Http\Middleware\IdempotentMiddleware;
use Grazulex\ApiIdempotency\IdempotencyManager;
use Grazulex\ApiIdempotency\Support\ResponseBodyCipher;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

const SECRET_BODY = '{"card":"4242 4242 4242 4242","email":"jane@example.com"}';

function encryptingManager(object $driver, bool $encrypt = true, ?ResponseBodyCipher $cipher = null): IdempotencyManager
{
    return new IdempotencyManager($driver, [
        'ttl' => 3600,
        'storage' => ['max_body_size' => 1048576, 'exclude_headers' => [], 'encrypt' => $encrypt],
    ], $cipher);
}

function cacheDriver(): CacheDriver
{
    return new CacheDriver(app('cache'), ['store' => 'array'], 3600);
}

function databaseDriver(): DatabaseDriver
{
    if (! Schema::hasTable('idempotency_keys')) {
        Schema::create('idempotency_keys', function ($table) {
            $table->id();
            $table->string('key', 255);
            $table->string('scope', 255)->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->integer('status_code');
            $table->json('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->integer('response_size')->default(0);
            $table->string('state', 20)->default('processing');
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->unique(['key', 'scope']);
        });
    }

    return new DatabaseDriver(app('db'), ['connection' => 'testing', 'table' => 'idempotency_keys'], 3600);
}

it('is disabled by default', function () {
    expect(config('api-idempotency.storage.encrypt'))->toBeFalse();

    $driver = cacheDriver();
    encryptingManager($driver, encrypt: false)->store('key_1234567890', new Response(SECRET_BODY, 201));

    expect($driver->get('key_1234567890')->responseBody)->toBe(SECRET_BODY);
});

it('stores the body encrypted and replays it decrypted', function (string $driverName) {
    $driver = $driverName === 'database' ? databaseDriver() : cacheDriver();
    $manager = encryptingManager($driver);

    $manager->store('key_1234567890', new Response(SECRET_BODY, 201), 'user_1');

    $stored = $driver->get('key_1234567890', 'user_1');
    expect($stored->responseBody)
        ->toStartWith(ResponseBodyCipher::PREFIX)
        ->not->toContain('4242')
        ->and($stored->responseSize)->toBe(strlen(SECRET_BODY));

    $record = $manager->get('key_1234567890', 'user_1');
    expect($record->responseBody)->toBe(SECRET_BODY)
        ->and($record->toResponse()->getContent())->toBe(SECRET_BODY)
        ->and($record->statusCode)->toBe(201);
})->with(['cache', 'database']);

it('stores the database column encrypted', function () {
    encryptingManager(databaseDriver())->store('key_1234567890', new Response(SECRET_BODY, 201));

    $column = app('db')->table('idempotency_keys')->where('key', 'key_1234567890')->value('response_body');

    expect($column)->toStartWith(ResponseBodyCipher::PREFIX)->not->toContain('jane@example.com');
});

it('keeps records readable when encryption is switched on or off', function () {
    $driver = cacheDriver();

    encryptingManager($driver, encrypt: false)->store('plain_1234567890', new Response('plain', 200));
    encryptingManager($driver, encrypt: true)->store('secret_1234567890', new Response(SECRET_BODY, 200));

    expect(encryptingManager($driver, encrypt: true)->get('plain_1234567890')->responseBody)->toBe('plain')
        ->and(encryptingManager($driver, encrypt: false)->get('secret_1234567890')->responseBody)->toBe(SECRET_BODY);
});

it('leaves empty bodies alone', function () {
    $driver = cacheDriver();

    encryptingManager($driver)->store('key_1234567890', new Response('', 204));

    expect($driver->get('key_1234567890')->responseBody)->toBeNull()
        ->and(encryptingManager($driver)->get('key_1234567890')->responseBody)->toBeNull();
});

it('throws instead of replaying or re-running when the body cannot be decrypted', function () {
    $driver = cacheDriver();
    $otherKey = new ResponseBodyCipher(new Encrypter(str_repeat('o', 32), 'aes-256-cbc'));
    encryptingManager($driver, cipher: $otherKey)->store('key_1234567890', new Response(SECRET_BODY, 201));

    encryptingManager($driver)->get('key_1234567890');
})->throws(DecryptException::class);

it('encrypts responses stored by the middleware and replays them', function () {
    config([
        'api-idempotency.storage.encrypt' => true,
        'api-idempotency.scope.enabled' => false,
        'api-idempotency.drivers.cache.store' => 'array',
    ]);
    app()->forgetInstance(IdempotencyManager::class);

    $calls = 0;
    $handle = function () use (&$calls) {
        $request = Request::create('/api/payments', 'POST', ['amount' => 10]);
        $request->headers->set('Idempotency-Key', 'payment_1234567890');

        return app(IdempotentMiddleware::class)->handle($request, function () use (&$calls) {
            $calls++;

            return new Response(SECRET_BODY, 201);
        });
    };

    $handle();
    $replay = $handle();

    $stored = app(IdempotencyManager::class)->getDriver()->get('payment_1234567890');

    expect($calls)->toBe(1)
        ->and($replay->getContent())->toBe(SECRET_BODY)
        ->and($replay->getStatusCode())->toBe(201)
        ->and($stored->responseBody)->toStartWith(ResponseBodyCipher::PREFIX);
});
