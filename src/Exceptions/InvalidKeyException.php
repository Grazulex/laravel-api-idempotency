<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Exceptions;

class InvalidKeyException extends IdempotencyException
{
    protected string $errorCode = 'IDEMPOTENCY_KEY_INVALID';

    protected int $httpStatus = 400;

    public function __construct(?string $message = null, public readonly ?string $invalidKey = null)
    {
        parent::__construct(
            $message ?? config('api-idempotency.errors.invalid_key.message', 'Invalid Idempotency-Key format.')
        );
    }
}
