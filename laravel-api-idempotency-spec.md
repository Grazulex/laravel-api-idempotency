# Laravel API Idempotency

> RFC-compliant idempotency support for Laravel APIs - Prevent duplicate operations, ensure safe retries

[![Latest Version on Packagist](https://img.shields.io/packagist/v/grazulex/laravel-api-idempotency.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-idempotency)
[![Tests](https://img.shields.io/github/actions/workflow/status/grazulex/laravel-api-idempotency/tests.yml?label=tests)](https://github.com/grazulex/laravel-api-idempotency/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/grazulex/laravel-api-idempotency.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-idempotency)
[![License](https://img.shields.io/packagist/l/grazulex/laravel-api-idempotency.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-idempotency)

---

## The Problem

In distributed systems and unreliable networks, clients may retry requests that actually succeeded. Without idempotency protection, this leads to:

- **Double charges** in payment systems
- **Duplicate orders** in e-commerce
- **Multiple webhook deliveries**
- **Inconsistent data states**

**Laravel API Idempotency** solves this by caching responses keyed by a unique `Idempotency-Key` header, ensuring identical requests return identical responses without re-executing the operation.

---

## Features

- 🔐 **RFC Draft Compliant** - Follows [IETF Idempotency-Key Header Draft](https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/)
- ⚡ **Zero Configuration** - Works out of the box with sensible defaults
- 🗄️ **Multiple Storage Drivers** - Cache, Redis, Database, or custom drivers
- 🎯 **Selective Application** - Apply per-route, per-controller, or globally
- 🔄 **Automatic Key Generation** - Optional client-side key generation helper
- 📊 **Conflict Detection** - Detects concurrent requests with same key
- 🧹 **Auto Cleanup** - Configurable TTL with automatic expiration
- 📝 **Detailed Logging** - Track idempotent hits for debugging
- 🧪 **Testing Helpers** - Fluent testing API for your test suite

---

## Requirements

- PHP 8.3+
- Laravel 11.x or 12.x

---

## Installation

```bash
composer require grazulex/laravel-api-idempotency
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="api-idempotency-config"
```

If using the database driver, publish and run migrations:

```bash
php artisan vendor:publish --tag="api-idempotency-migrations"
php artisan migrate
```

---

## Quick Start

### Basic Usage

Apply the middleware to routes that need idempotency protection:

```php
// routes/api.php
use Illuminate\Support\Facades\Route;

Route::post('/payments', [PaymentController::class, 'store'])
    ->middleware('idempotent');

Route::post('/orders', [OrderController::class, 'store'])
    ->middleware('idempotent');
```

Client sends request with idempotency key:

```bash
curl -X POST https://api.example.com/payments \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: pay_abc123_unique_key" \
  -d '{"amount": 9999, "currency": "EUR"}'
```

**First request:** Executes normally, response cached  
**Same key again:** Returns cached response, no re-execution

### Response Headers

```http
HTTP/1.1 201 Created
Content-Type: application/json
Idempotency-Key: pay_abc123_unique_key
X-Idempotent-Replayed: false

{"id": "pay_xyz", "amount": 9999, "status": "completed"}
```

On replay:

```http
HTTP/1.1 201 Created
Content-Type: application/json
Idempotency-Key: pay_abc123_unique_key
X-Idempotent-Replayed: true
X-Original-Request-Time: 2025-01-15T10:30:00+00:00

{"id": "pay_xyz", "amount": 9999, "status": "completed"}
```

---

## Configuration

```php
// config/api-idempotency.php

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
```

---

## Advanced Usage

### Controller-Level Application

```php
namespace App\Http\Controllers\Api;

use Grazulex\ApiIdempotency\Attributes\Idempotent;
use Grazulex\ApiIdempotency\Attributes\IdempotentExcept;

#[Idempotent]
class PaymentController extends Controller
{
    // All methods are idempotent
    public function store(Request $request) { /* ... */ }
    
    #[IdempotentExcept]
    public function index() { /* ... */ } // Excluded
}
```

### Custom TTL Per Route

```php
Route::post('/payments', [PaymentController::class, 'store'])
    ->middleware('idempotent:ttl=172800'); // 48 hours

Route::post('/webhooks', [WebhookController::class, 'receive'])
    ->middleware('idempotent:ttl=3600'); // 1 hour
```

### Require Key on Specific Routes

```php
Route::post('/critical-operation', CriticalController::class)
    ->middleware('idempotent:required');
```

### Custom Scope

```php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/team/{team}/invoices', InvoiceController::class)
        ->middleware('idempotent:scope=team');
});
```

### Programmatic Usage

```php
use Grazulex\ApiIdempotency\Facades\Idempotency;

class PaymentController extends Controller
{
    public function store(PaymentRequest $request)
    {
        $key = $request->header('Idempotency-Key');
        
        // Check if already processed
        if ($cached = Idempotency::get($key)) {
            return $cached->toResponse();
        }
        
        // Process payment
        $payment = $this->processPayment($request);
        
        // Manually store (useful for conditional caching)
        Idempotency::store($key, response()->json($payment, 201));
        
        return response()->json($payment, 201);
    }
}
```

### Exclude Specific Responses

```php
use Grazulex\ApiIdempotency\Facades\Idempotency;

public function store(Request $request)
{
    try {
        $result = $this->process($request);
        return response()->json($result, 201);
    } catch (ValidationException $e) {
        // Don't cache validation errors - client should fix and retry
        Idempotency::skip();
        throw $e;
    }
}
```

### Key Generation Helper

Provide a client-side SDK or endpoint:

```php
use Grazulex\ApiIdempotency\IdempotencyKey;

// Generate a unique key
$key = IdempotencyKey::generate(); 
// => "idem_01HQ3K4M5N6P7R8S9T0UVWXYZ"

// Generate with prefix
$key = IdempotencyKey::generate('pay'); 
// => "pay_01HQ3K4M5N6P7R8S9T0UVWXYZ"

// Generate deterministic key from data (for retry scenarios)
$key = IdempotencyKey::fromData([
    'user_id' => 123,
    'action' => 'create_payment',
    'amount' => 9999,
]);
// => "idem_sha256_a1b2c3d4..."
```

---

## Events

```php
// app/Providers/EventServiceProvider.php

use Grazulex\ApiIdempotency\Events\IdempotentRequestProcessed;
use Grazulex\ApiIdempotency\Events\IdempotentRequestReplayed;
use Grazulex\ApiIdempotency\Events\IdempotentConflictDetected;
use Grazulex\ApiIdempotency\Events\IdempotentPayloadMismatch;

protected $listen = [
    IdempotentRequestProcessed::class => [
        LogIdempotentRequest::class,
    ],
    IdempotentRequestReplayed::class => [
        NotifyReplayedRequest::class,
    ],
    IdempotentConflictDetected::class => [
        AlertOnConflict::class,
    ],
    IdempotentPayloadMismatch::class => [
        SecurityAlertListener::class, // Potential replay attack
    ],
];
```

---

## Artisan Commands

```bash
# View idempotency statistics
php artisan idempotency:stats
# ┌─────────────────┬─────────┐
# │ Metric          │ Value   │
# ├─────────────────┼─────────┤
# │ Total Keys      │ 15,432  │
# │ Hits (24h)      │ 342     │
# │ Hit Rate        │ 2.2%    │
# │ Avg Response    │ 1.2 KB  │
# │ Storage Used    │ 18.5 MB │
# └─────────────────┴─────────┘

# Cleanup expired keys (usually handled by TTL, but useful for DB driver)
php artisan idempotency:cleanup
# Cleaned up 1,523 expired idempotency records.

# Cleanup specific key (useful for debugging/support)
php artisan idempotency:forget pay_abc123
# Idempotency key 'pay_abc123' has been removed.

# List recent idempotent requests (debugging)
php artisan idempotency:list --limit=20
```

---

## Database Schema

When using the database driver:

```php
// database/migrations/create_idempotency_keys_table.php

Schema::create('idempotency_keys', function (Blueprint $table) {
    $table->id();
    $table->string('key', 255)->unique();
    $table->string('scope')->nullable()->index();
    $table->string('fingerprint', 64)->nullable();
    $table->string('method', 10);
    $table->string('path', 500);
    $table->unsignedSmallInteger('status_code');
    $table->json('response_headers')->nullable();
    $table->longText('response_body')->nullable();
    $table->unsignedInteger('response_size')->default(0);
    $table->enum('state', ['processing', 'completed', 'failed'])->default('processing');
    $table->timestamp('locked_until')->nullable();
    $table->timestamp('created_at');
    $table->timestamp('expires_at')->index();

    $table->index(['key', 'scope']);
    $table->index(['state', 'locked_until']);
});
```

---

## Testing

### Testing Helpers

```php
use Grazulex\ApiIdempotency\Testing\IdempotencyFake;

class PaymentTest extends TestCase
{
    public function test_payment_is_idempotent(): void
    {
        $key = 'test_key_123';
        $payload = ['amount' => 9999, 'currency' => 'EUR'];

        // First request
        $response1 = $this->postJson('/api/payments', $payload, [
            'Idempotency-Key' => $key,
        ]);

        $response1->assertStatus(201)
            ->assertHeader('Idempotency-Key', $key)
            ->assertHeader('X-Idempotent-Replayed', 'false');

        // Second request with same key
        $response2 = $this->postJson('/api/payments', $payload, [
            'Idempotency-Key' => $key,
        ]);

        $response2->assertStatus(201)
            ->assertHeader('X-Idempotent-Replayed', 'true')
            ->assertJson($response1->json());

        // Verify only one payment was created
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_different_payload_with_same_key_fails(): void
    {
        $key = 'test_key_456';

        $this->postJson('/api/payments', ['amount' => 1000], [
            'Idempotency-Key' => $key,
        ])->assertStatus(201);

        $this->postJson('/api/payments', ['amount' => 2000], [
            'Idempotency-Key' => $key,
        ])->assertStatus(422)
            ->assertJson(['code' => 'IDEMPOTENCY_PAYLOAD_MISMATCH']);
    }

    public function test_missing_required_key_returns_400(): void
    {
        $this->postJson('/api/payments', ['amount' => 1000])
            ->assertStatus(400)
            ->assertJson(['code' => 'IDEMPOTENCY_KEY_MISSING']);
    }
}
```

### Fake for Unit Tests

```php
use Grazulex\ApiIdempotency\Facades\Idempotency;

public function test_service_handles_idempotency(): void
{
    Idempotency::fake();

    // Your test code...

    Idempotency::assertStored('expected_key');
    Idempotency::assertReplayed('expected_key');
    Idempotency::assertStoredCount(5);
}
```

---

## Integration Examples

### Stripe-Style Implementation

```php
// Client SDK helper
class ApiClient
{
    public function createPayment(array $data, ?string $idempotencyKey = null): Payment
    {
        $key = $idempotencyKey ?? IdempotencyKey::generate('pay');
        
        $response = Http::withHeaders([
            'Idempotency-Key' => $key,
        ])->post('/api/payments', $data);
        
        return Payment::fromResponse($response);
    }
}

// Usage
$payment = $client->createPayment(
    ['amount' => 9999],
    'pay_user123_order456' // Deterministic key for this operation
);
```

### With Queue Jobs

```php
class ProcessPaymentJob implements ShouldQueue
{
    public function __construct(
        public string $idempotencyKey,
        public array $paymentData,
    ) {}

    public function handle(): void
    {
        // Use same idempotency key for external API calls
        $response = Stripe::charges()->create(
            $this->paymentData,
            ['idempotency_key' => $this->idempotencyKey]
        );
    }

    public function uniqueId(): string
    {
        return $this->idempotencyKey;
    }
}
```

---

## Error Responses

### Missing Key (when required)

```json
{
    "success": false,
    "error": {
        "code": "IDEMPOTENCY_KEY_MISSING",
        "message": "Idempotency-Key header is required for this request."
    }
}
```

### Invalid Key Format

```json
{
    "success": false,
    "error": {
        "code": "IDEMPOTENCY_KEY_INVALID",
        "message": "Invalid Idempotency-Key format. Key must be 10-255 alphanumeric characters."
    }
}
```

### Payload Mismatch

```json
{
    "success": false,
    "error": {
        "code": "IDEMPOTENCY_PAYLOAD_MISMATCH",
        "message": "Idempotency-Key has already been used with different request parameters.",
        "details": {
            "original_fingerprint": "sha256:a1b2c3...",
            "current_fingerprint": "sha256:x9y8z7..."
        }
    }
}
```

### Concurrent Request Conflict

```json
{
    "success": false,
    "error": {
        "code": "IDEMPOTENCY_CONFLICT",
        "message": "A request with this Idempotency-Key is currently being processed.",
        "retry_after": 5
    }
}
```

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Request Flow                              │
└─────────────────────────────────────────────────────────────────┘

    ┌──────────┐     ┌───────────────────┐     ┌──────────────┐
    │  Client  │────▶│ IdempotentMiddleware│────▶│  Controller  │
    └──────────┘     └───────────────────┘     └──────────────┘
         │                    │                        │
         │                    ▼                        │
         │           ┌───────────────┐                 │
         │           │ Check Storage │                 │
         │           └───────────────┘                 │
         │                    │                        │
         │         ┌──────────┴──────────┐             │
         │         │                     │             │
         │         ▼                     ▼             │
         │    ┌─────────┐          ┌──────────┐       │
         │    │  Found  │          │ Not Found│       │
         │    └─────────┘          └──────────┘       │
         │         │                     │             │
         │         ▼                     ▼             │
         │   ┌───────────┐       ┌─────────────┐      │
         │   │  Verify   │       │  Lock Key   │      │
         │   │Fingerprint│       │ (Processing)│      │
         │   └───────────┘       └─────────────┘      │
         │         │                     │             │
         │         ▼                     ▼             │
         │   ┌───────────┐       ┌─────────────┐      │
         │   │  Return   │       │   Execute   │◀─────┘
         │   │  Cached   │       │   Request   │
         │   └───────────┘       └─────────────┘
         │         │                     │
         │         │                     ▼
         │         │             ┌─────────────┐
         │         │             │   Store     │
         │         │             │  Response   │
         │         │             └─────────────┘
         │         │                     │
         │         ▼                     ▼
         │   ┌─────────────────────────────────┐
         └───│           Response              │
             └─────────────────────────────────┘
```

---

## Best Practices

### Key Generation

```php
// ❌ Bad: Predictable, not unique enough
'key123'
'user_1_payment'

// ✅ Good: Unique, includes context
'pay_01HQ3K4M5N6P7R8S9T0UVWXYZ'
'order_user123_cart456_20250115'
IdempotencyKey::fromData(['user' => 123, 'cart' => 456])
```

### Appropriate TTL

| Use Case | Recommended TTL |
|----------|-----------------|
| Payments | 24-72 hours |
| Order Creation | 24 hours |
| Webhooks | 1-4 hours |
| Form Submissions | 1 hour |
| Rate-Limited Operations | 15 minutes |

### When NOT to Use

- **GET requests** - Already idempotent by nature
- **Listing endpoints** - Data changes between requests
- **Search/Filter** - Results vary based on data state
- **Highly time-sensitive** - Where stale responses are problematic

---

## Comparison with Other Solutions

| Feature | This Package | DIY Cache | Stripe SDK |
|---------|-------------|-----------|------------|
| Zero Config | ✅ | ❌ | N/A |
| Multi-Driver | ✅ | ❌ | N/A |
| Fingerprinting | ✅ | ❌ | ✅ |
| Conflict Detection | ✅ | ❌ | ✅ |
| Laravel Integration | ✅ | ⚠️ | ❌ |
| Scoping | ✅ | ❌ | ❌ |
| Testing Helpers | ✅ | ❌ | ❌ |

---

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email security@grazulex.dev instead of using the issue tracker.

## Credits

- [Jean-Marc Strauven](https://github.com/Grazulex)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

## See Also

- [laravel-api-kit](https://github.com/Grazulex/laravel-api-kit) - API-only Laravel starter kit
- [laravel-apiroute](https://github.com/Grazulex/laravel-apiroute) - API versioning lifecycle management
- [laravel-api-throttle-smart](https://github.com/Grazulex/laravel-api-throttle-smart) - Intelligent rate limiting
