<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Commands;

use Grazulex\ApiIdempotency\IdempotencyManager;
use Illuminate\Console\Command;

class IdempotencyStatsCommand extends Command
{
    protected $signature = 'idempotency:stats';

    protected $description = 'View idempotency statistics';

    public function handle(IdempotencyManager $manager): int
    {
        $stats = $manager->stats();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Keys', number_format($stats['total'])],
                ['Hits (24h)', number_format($stats['hits'])],
                ['Storage Used', $this->formatBytes($stats['size'])],
                ['Driver', config('api-idempotency.driver', 'cache')],
                ['TTL', $this->formatDuration(config('api-idempotency.ttl', 86400))],
            ]
        );

        return self::SUCCESS;
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 2).' '.$units[$i];
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        }

        if ($seconds < 3600) {
            $minutes = round($seconds / 60);

            return "{$minutes} minutes";
        }

        $hours = round($seconds / 3600);

        return "{$hours} hours";
    }
}
