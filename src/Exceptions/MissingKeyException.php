<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Exceptions;

class MissingKeyException extends IdempotencyException
{
    protected string $errorCode = 'IDEMPOTENCY_KEY_MISSING';

    protected int $httpStatus = 400;

    public function __construct(?string $message = null)
    {
        parent::__construct(
            $message ?? config('api-idempotency.errors.missing_key.message', 'Idempotency-Key header is required for this request.')
        );
    }
}
