<?php

declare(strict_types=1);

use Grazulex\ApiIdempotency\IdempotencyManager;

if (! function_exists('idempotency')) {
    /**
     * Get the idempotency manager instance.
     */
    function idempotency(): IdempotencyManager
    {
        return app(IdempotencyManager::class);
    }
}
