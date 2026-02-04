<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Events;

use DateTimeImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;

class IdempotentRequestReplayed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $key,
        public readonly ?string $scope,
        public readonly Request $request,
        public readonly DateTimeImmutable $originalRequestTime,
        public readonly int $hitCount,
    ) {}
}
