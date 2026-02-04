<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Support;

use Grazulex\ApiIdempotency\Exceptions\InvalidKeyException;
use Illuminate\Support\Str;

class IdempotencyKey
{
    /**
     * Generate a unique idempotency key.
     */
    public static function generate(?string $prefix = null): string
    {
        $prefix = $prefix ?? 'idem';
        $ulid = Str::ulid()->toBase32();

        return "{$prefix}_{$ulid}";
    }

    /**
     * Generate a deterministic key from data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromData(array $data, ?string $prefix = null): string
    {
        $prefix = $prefix ?? 'idem';
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return "{$prefix}_sha256_".substr($hash, 0, 32);
    }

    /**
     * Validate a key against configured rules.
     *
     * @throws InvalidKeyException
     */
    public static function validate(string $key): void
    {
        $minLength = config('api-idempotency.key.min_length', 10);
        $maxLength = config('api-idempotency.key.max_length', 255);
        $pattern = config('api-idempotency.key.pattern', '/^[a-zA-Z0-9_-]+$/');

        if (strlen($key) < $minLength) {
            throw new InvalidKeyException(
                "Idempotency-Key must be at least {$minLength} characters.",
                $key
            );
        }

        if (strlen($key) > $maxLength) {
            throw new InvalidKeyException(
                "Idempotency-Key must not exceed {$maxLength} characters.",
                $key
            );
        }

        if (! preg_match($pattern, $key)) {
            throw new InvalidKeyException(
                'Idempotency-Key contains invalid characters. Only alphanumeric, dash, and underscore are allowed.',
                $key
            );
        }
    }

    /**
     * Check if a key is valid without throwing.
     */
    public static function isValid(string $key): bool
    {
        try {
            self::validate($key);

            return true;
        } catch (InvalidKeyException) {
            return false;
        }
    }
}
