<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Exceptions;

use Illuminate\Http\JsonResponse;

class ConflictException extends IdempotencyException
{
    protected string $errorCode = 'IDEMPOTENCY_CONFLICT';

    protected int $httpStatus = 409;

    public function __construct(
        ?string $message = null,
        public readonly int $retryAfter = 5,
    ) {
        parent::__construct(
            $message ?? config('api-idempotency.errors.conflict.message', 'A request with this Idempotency-Key is currently being processed.')
        );
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'retry_after' => $this->retryAfter,
            ],
        ], $this->httpStatus, [
            'Retry-After' => (string) $this->retryAfter,
        ]);
    }
}
