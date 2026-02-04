<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Facades;

use Grazulex\ApiIdempotency\IdempotencyManager;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;
use Grazulex\ApiIdempotency\Testing\IdempotencyFake;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;

/**
 * @method static IdempotencyRecord|null get(string $key, ?string $scope = null)
 * @method static bool store(string $key, Response $response, ?string $scope = null)
 * @method static bool forget(string $key, ?string $scope = null)
 * @method static void skip()
 * @method static bool shouldSkip()
 * @method static array stats()
 * @method static array list(int $limit = 20)
 * @method static int cleanup()
 *
 * @see IdempotencyManager
 */
class Idempotency extends Facade
{
    /**
     * Replace the bound instance with a fake.
     */
    public static function fake(): IdempotencyFake
    {
        static::swap($fake = new IdempotencyFake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return IdempotencyManager::class;
    }
}
