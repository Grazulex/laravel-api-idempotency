<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency;

use Grazulex\ApiIdempotency\Commands\IdempotencyCleanupCommand;
use Grazulex\ApiIdempotency\Commands\IdempotencyForgetCommand;
use Grazulex\ApiIdempotency\Commands\IdempotencyListCommand;
use Grazulex\ApiIdempotency\Commands\IdempotencyStatsCommand;
use Grazulex\ApiIdempotency\Contracts\StorageDriverInterface;
use Grazulex\ApiIdempotency\Drivers\CacheDriver;
use Grazulex\ApiIdempotency\Drivers\DatabaseDriver;
use Grazulex\ApiIdempotency\Drivers\DynamoDbDriver;
use Grazulex\ApiIdempotency\Drivers\RedisDriver;
use Grazulex\ApiIdempotency\Http\Middleware\IdempotentMiddleware;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class ApiIdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/api-idempotency.php',
            'api-idempotency'
        );

        $this->app->singleton(IdempotencyManager::class, function (Application $app): IdempotencyManager {
            return new IdempotencyManager(
                $this->resolveDriver($app),
                $app['config']->get('api-idempotency')
            );
        });

        $this->app->alias(IdempotencyManager::class, 'idempotency');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/api-idempotency.php' => config_path('api-idempotency.php'),
        ], 'api-idempotency-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'api-idempotency-migrations');

        $this->registerMiddleware();
        $this->registerCommands();
    }

    protected function resolveDriver(Application $app): StorageDriverInterface
    {
        $driver = $app['config']->get('api-idempotency.driver', 'cache');
        $config = $app['config']->get("api-idempotency.drivers.{$driver}", []);
        $ttl = $app['config']->get('api-idempotency.ttl', 86400);

        return match ($driver) {
            'cache' => new CacheDriver($app['cache'], $config, $ttl),
            'redis' => new RedisDriver($app['redis'], $config, $ttl),
            'database' => new DatabaseDriver($app['db'], $config, $ttl),
            'dynamodb' => new DynamoDbDriver($config, $ttl),
            default => new CacheDriver($app['cache'], $config, $ttl),
        };
    }

    protected function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('idempotent', IdempotentMiddleware::class);
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                IdempotencyStatsCommand::class,
                IdempotencyCleanupCommand::class,
                IdempotencyForgetCommand::class,
                IdempotencyListCommand::class,
            ]);
        }
    }
}
