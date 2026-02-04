<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class IdempotencyException extends Exception
{
    protected string $errorCode = 'IDEMPOTENCY_ERROR';

    protected int $httpStatus = 500;

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ], $this->httpStatus);
    }
}
