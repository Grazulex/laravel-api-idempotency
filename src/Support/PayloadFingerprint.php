<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Support;

use Illuminate\Http\Request;

class PayloadFingerprint
{
    /**
     * Generate a fingerprint for the given request.
     */
    public static function generate(Request $request): string
    {
        $config = config('api-idempotency.fingerprint', []);
        $algorithm = $config['algorithm'] ?? 'sha256';
        $data = [];

        if ($config['include_method'] ?? true) {
            $data['method'] = $request->method();
        }

        if ($config['include_path'] ?? true) {
            $data['path'] = $request->path();
        }

        if ($config['include_body'] ?? true) {
            $body = $request->all();
            $excludeFields = $config['exclude_fields'] ?? [];

            foreach ($excludeFields as $field) {
                unset($body[$field]);
            }

            ksort($body);
            $data['body'] = $body;
        }

        return hash($algorithm, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Compare two fingerprints.
     */
    public static function compare(string $fingerprint1, string $fingerprint2): bool
    {
        return hash_equals($fingerprint1, $fingerprint2);
    }
}
