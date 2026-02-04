<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Commands;

use Grazulex\ApiIdempotency\IdempotencyManager;
use Illuminate\Console\Command;

class IdempotencyForgetCommand extends Command
{
    protected $signature = 'idempotency:forget
                            {key : The idempotency key to remove}
                            {--scope= : The scope of the key}';

    protected $description = 'Remove a specific idempotency key';

    public function handle(IdempotencyManager $manager): int
    {
        $key = $this->argument('key');
        $scope = $this->option('scope');

        $success = $manager->forget($key, $scope);

        if ($success) {
            $this->info("Idempotency key '{$key}' has been removed.");
        } else {
            $this->warn("Idempotency key '{$key}' was not found or could not be removed.");
        }

        return self::SUCCESS;
    }
}
