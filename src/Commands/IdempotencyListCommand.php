<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Commands;

use Grazulex\ApiIdempotency\IdempotencyManager;
use Illuminate\Console\Command;

class IdempotencyListCommand extends Command
{
    protected $signature = 'idempotency:list
                            {--limit=20 : Number of records to show}';

    protected $description = 'List recent idempotent requests';

    public function handle(IdempotencyManager $manager): int
    {
        $limit = (int) $this->option('limit');
        $records = $manager->list($limit);

        if (empty($records)) {
            $this->info('No idempotency records found.');
            $this->newLine();
            $this->comment('Note: Listing may not be supported by all drivers (cache, dynamodb).');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                $this->truncate($record->key, 30),
                $record->scope ?? '-',
                $record->method,
                $this->truncate($record->path, 25),
                $record->statusCode,
                $record->state,
                $record->createdAt->format('Y-m-d H:i:s'),
            ];
        }

        $this->table(
            ['Key', 'Scope', 'Method', 'Path', 'Status', 'State', 'Created'],
            $rows
        );

        return self::SUCCESS;
    }

    protected function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return substr($value, 0, $length - 3).'...';
    }
}
