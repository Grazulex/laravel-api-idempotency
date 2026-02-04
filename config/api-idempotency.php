<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Enable/Disable Idempotency
    |--------------------------------------------------------------------------
    */
    'enabled' => env('API_IDEMPOTENCY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Header Name
    |--------------------------------------------------------------------------
    | The HTTP header containing the idempotency key.
    | Standard: "Idempotency-Key" (RFC Draft)
    | Stripe uses: "Idempotency-Key"
    | Some APIs use: "X-Idempotency-Key"
    */
    'header' => env('API_IDEMPOTENCY_HEADER', 'Idempotency-Key'),

    /*
    |--------------------------------------------------------------------------
    | Key Requirements
    |--------------------------------------------------------------------------
    */
    'key' => [
        // Require idempotency key on applicable routes (returns 400 if missing)
        'required' => env('API_IDEMPOTENCY_KEY_REQUIRED', false),

        // Minimum key length
        'min_length' => 10,

        // Maximum key length
        'max_length' => 255,

        // Allowed characters regex (alphanumeric, dash, underscore by default)
        'pattern' => '/^[a-zA-Z0-9_-]+$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    | Supported: "cache", "redis", "database", "dynamodb"
    */
    'driver' => env('API_IDEMPOTENCY_DRIVER', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Driver-Specific Configuration
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'cache' => [
            'store' => env('API_IDEMPOTENCY_CACHE_STORE', 'default'),
            'prefix' => 'idempotency:',
        ],

        'redis' => [
            'connection' => env('API_IDEMPOTENCY_REDIS_CONNECTION', 'default'),
            'prefix' => 'idempotency:',
        ],

        'database' => [
            'connection' => env('DB_CONNECTION', 'mysql'),
            'table' => 'idempotency_keys',
        ],

        'dynamodb' => [
            'table' => env('API_IDEMPOTENCY_DYNAMODB_TABLE', 'idempotency_keys'),
            'region' => env('AWS_DEFAULT_REGION', 'eu-west-1'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Time To Live (TTL)
    |--------------------------------------------------------------------------
    | How long to store idempotency records (in seconds).
    | Stripe uses 24 hours. PayPal uses 72 hours.
    */
    'ttl' => env('API_IDEMPOTENCY_TTL', 86400), // 24 hours

    /*
    |--------------------------------------------------------------------------
    | Conflict Handling
    |--------------------------------------------------------------------------
    | What to do when a request is in progress with the same key.
    | Options: "wait", "reject"
    */
    'conflict' => [
        'strategy' => 'wait',
        'wait_timeout' => 10, // seconds
        'retry_interval' => 100, // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload Fingerprinting
    |--------------------------------------------------------------------------
    | Verify that replay requests have the same payload as original.
    | Prevents key reuse with different data (security feature).
    */
    'fingerprint' => [
        'enabled' => true,
        'algorithm' => 'sha256',
        'include_path' => true,
        'include_method' => true,
        'include_body' => true,
        'exclude_fields' => ['timestamp', 'nonce'], // Fields to ignore
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Storage
    |--------------------------------------------------------------------------
    */
    'storage' => [
        // Store full response body (required for replay)
        'store_body' => true,

        // Maximum body size to store (bytes). Larger responses won't be cached.
        'max_body_size' => 1048576, // 1 MB

        // Store response headers
        'store_headers' => true,

        // Headers to exclude from storage
        'exclude_headers' => [
            'Set-Cookie',
            'X-Request-Id',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Methods
    |--------------------------------------------------------------------------
    | Which HTTP methods should support idempotency.
    | POST is the primary use case. PUT/PATCH are naturally idempotent but
    | may benefit from this for response caching.
    */
    'methods' => ['POST'],

    /*
    |--------------------------------------------------------------------------
    | Response Headers
    |--------------------------------------------------------------------------
    */
    'headers' => [
        // Include idempotency key in response
        'echo_key' => true,

        // Include replay indicator
        'replay_indicator' => true,
        'replay_header' => 'X-Idempotent-Replayed',

        // Include original request timestamp on replay
        'original_time' => true,
        'original_time_header' => 'X-Original-Request-Time',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoping
    |--------------------------------------------------------------------------
    | Scope idempotency keys to prevent cross-user/cross-tenant collisions.
    */
    'scope' => [
        'enabled' => true,

        // Scope resolver: 'user', 'tenant', 'ip', or custom callable
        'resolver' => 'user',

        // Custom resolver example:
        // 'resolver' => fn($request) => $request->user()?->team_id,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('API_IDEMPOTENCY_LOGGING', true),
        'channel' => env('API_IDEMPOTENCY_LOG_CHANNEL', 'default'),
        'level' => 'info',

        // What to log
        'log_hits' => true,      // Log when cached response is returned
        'log_misses' => false,   // Log when new request is processed
        'log_conflicts' => true, // Log concurrent request conflicts
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Responses
    |--------------------------------------------------------------------------
    */
    'errors' => [
        'missing_key' => [
            'status' => 400,
            'message' => 'Idempotency-Key header is required for this request.',
            'code' => 'IDEMPOTENCY_KEY_MISSING',
        ],
        'invalid_key' => [
            'status' => 400,
            'message' => 'Invalid Idempotency-Key format.',
            'code' => 'IDEMPOTENCY_KEY_INVALID',
        ],
        'payload_mismatch' => [
            'status' => 422,
            'message' => 'Idempotency-Key has already been used with different request parameters.',
            'code' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
        ],
        'conflict' => [
            'status' => 409,
            'message' => 'A request with this Idempotency-Key is currently being processed.',
            'code' => 'IDEMPOTENCY_CONFLICT',
        ],
    ],
];
