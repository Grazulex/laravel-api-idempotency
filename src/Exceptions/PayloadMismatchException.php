<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Exceptions;

use Illuminate\Http\JsonResponse;

class PayloadMismatchException extends IdempotencyException
{
    protected string $errorCode = 'IDEMPOTENCY_PAYLOAD_MISMATCH';

    protected int $httpStatus = 422;

    public function __construct(
        ?string $message = null,
        public readonly ?string $originalFingerprint = null,
        public readonly ?string $currentFingerprint = null,
    ) {
        parent::__construct(
            $message ?? config('api-idempotency.errors.payload_mismatch.message', 'Idempotency-Key has already been used with different request parameters.')
        );
    }

    public function render(): JsonResponse
    {
        $response = [
            'success' => false,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ];

        if ($this->originalFingerprint !== null && $this->currentFingerprint !== null) {
            $response['error']['details'] = [
                'original_fingerprint' => 'sha256:'.substr($this->originalFingerprint, 0, 8).'...',
                'current_fingerprint' => 'sha256:'.substr($this->currentFingerprint, 0, 8).'...',
            ];
        }

        return new JsonResponse($response, $this->httpStatus);
    }
}
