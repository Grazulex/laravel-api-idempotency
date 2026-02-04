<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Commands;

use Grazulex\ApiIdempotency\IdempotencyManager;
use Illuminate\Console\Command;

class IdempotencyCleanupCommand extends Command
{
    protected $signature = 'idempotency:cleanup';

    protected $description = 'Clean up expired idempotency records';

    public function handle(IdempotencyManager $manager): int
    {
        $count = $manager->cleanup();

        if ($count === 0) {
            $this->info('No expired idempotency records to clean up.');
        } else {
            $this->info("Cleaned up {$count} expired idempotency records.");
        }

        return self::SUCCESS;
    }
}
