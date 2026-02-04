<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Tests;

use Grazulex\ApiIdempotency\ApiIdempotencyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ApiIdempotencyServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('api-idempotency.enabled', true);
        $app['config']->set('api-idempotency.driver', 'cache');
    }
}
