# Laravel API Idempotency

> Complete API idempotency lifecycle management for Laravel - Prevent duplicate operations, ensure safe retries

> [!TIP]
> **What Laravel API Idempotency does for you** — Stop duplicate charges, double orders and repeated side effects when a client retries a request. Add one middleware and your API safely returns the same response for the same `Idempotency-Key` — no more defensive code in every controller.
>
> **This package is free and maintained on my own time.** If it saves you hours, a small contribution helps me keep it going:
> [💖 GitHub Sponsors](https://github.com/sponsors/Grazulex) · [☕ Buy Me a Coffee](https://buymeacoffee.com/grazulex) · [PayPal](https://paypal.me/strauven)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/grazulex/laravel-api-idempotency.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-idempotency)
[![Tests](https://img.shields.io/github/actions/workflow/status/grazulex/laravel-api-idempotency/tests.yml?label=tests)](https://github.com/grazulex/laravel-api-idempotency/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/grazulex/laravel-api-idempotency.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-idempotency)
[![License](https://img.shields.io/packagist/l/grazulex/laravel-api-idempotency.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-idempotency)

---

## Features

- **RFC Draft Compliant** - Follows [IETF Idempotency-Key Header Draft](https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/)
- **Multiple Storage Drivers** - Cache, Redis, Database, or DynamoDB
- **Payload Fingerprinting** - SHA256 verification prevents key reuse with different data
- **Conflict Detection** - Handles concurrent requests with wait/reject strategies
- **Scoping** - Scope keys to user, tenant, IP, or custom resolver
- **Zero Configuration** - Works out of the box with sensible defaults
- **Artisan Commands** - Stats, cleanup, and management tools
- **Testing Helpers** - Fluent testing API with `IdempotencyFake`

---

## Requirements

- PHP 8.3+
- Laravel 12.x or 13.x

---

## Installation

```bash
composer require grazulex/laravel-api-idempotency
```

Publish the configuration:

```bash
php artisan vendor:publish --tag="api-idempotency-config"
```

For database driver, publish migrations:

```bash
php artisan vendor:publish --tag="api-idempotency-migrations"
php artisan migrate
```

---

## Quick Start

Apply the middleware to routes:

```php
// routes/api.php
Route::post('/payments', [PaymentController::class, 'store'])
    ->middleware('idempotent');

Route::post('/orders', [OrderController::class, 'store'])
    ->middleware('idempotent:required'); // Key required
```

Client request with idempotency key:

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
Idempotency-Key: pay_abc123_unique_key
X-Idempotent-Replayed: false
```

On replay:

```http
HTTP/1.1 201 Created
Idempotency-Key: pay_abc123_unique_key
X-Idempotent-Replayed: true
X-Original-Request-Time: 2025-01-15T10:30:00+00:00
```

---

## Middleware Options

```php
// Custom TTL (seconds)
->middleware('idempotent:ttl=172800')  // 48 hours

// Require key (returns 400 if missing)
->middleware('idempotent:required')

// Custom scope
->middleware('idempotent:scope=team')

// Combined options
->middleware('idempotent:required,ttl=3600')
```

---

## PHP Attributes

```php
use Grazulex\ApiIdempotency\Attributes\Idempotent;
use Grazulex\ApiIdempotency\Attributes\IdempotentExcept;

#[Idempotent]
class PaymentController extends Controller
{
    public function store(Request $request) { /* ... */ }

    #[IdempotentExcept]
    public function index() { /* ... */ } // Excluded
}
```

---

## Programmatic Usage

```php
use Grazulex\ApiIdempotency\Facades\Idempotency;

// Check if already processed
if ($cached = Idempotency::get($key)) {
    return $cached->toResponse();
}

// Store manually
Idempotency::store($key, response()->json($data, 201));

// Skip caching (e.g., for validation errors)
Idempotency::skip();
```

---

## Key Generation

```php
use Grazulex\ApiIdempotency\Support\IdempotencyKey;

// Generate unique key
$key = IdempotencyKey::generate();         // "idem_01HQ3K4M..."
$key = IdempotencyKey::generate('pay');    // "pay_01HQ3K4M..."

// Deterministic key from data
$key = IdempotencyKey::fromData([
    'user_id' => 123,
    'action' => 'create_payment',
]);
```

---

## Artisan Commands

```bash
# View statistics
php artisan idempotency:stats

# Cleanup expired keys
php artisan idempotency:cleanup

# Remove specific key
php artisan idempotency:forget pay_abc123

# List recent keys
php artisan idempotency:list --limit=20
```

---

## Events

```php
use Grazulex\ApiIdempotency\Events\IdempotentRequestProcessed;
use Grazulex\ApiIdempotency\Events\IdempotentRequestReplayed;
use Grazulex\ApiIdempotency\Events\IdempotentConflictDetected;
use Grazulex\ApiIdempotency\Events\IdempotentPayloadMismatch;
```

---

## Configuration

```php
// config/api-idempotency.php
return [
    'enabled' => env('API_IDEMPOTENCY_ENABLED', true),
    'header' => env('API_IDEMPOTENCY_HEADER', 'Idempotency-Key'),

    'key' => [
        'required' => false,
        'min_length' => 10,
        'max_length' => 255,
        'pattern' => '/^[a-zA-Z0-9_-]+$/',
    ],

    // Drivers: cache, redis, database, dynamodb
    'driver' => env('API_IDEMPOTENCY_DRIVER', 'cache'),

    'drivers' => [
        'cache' => [
            'store' => 'default',
            'prefix' => 'idempotency:',
        ],
        'redis' => [
            'connection' => 'default',
            'prefix' => 'idempotency:',
        ],
        'database' => [
            'connection' => null,
            'table' => 'idempotency_keys',
        ],
        'dynamodb' => [
            'table' => 'idempotency_keys',
            'region' => 'eu-west-1',
        ],
    ],

    'ttl' => env('API_IDEMPOTENCY_TTL', 86400), // 24 hours

    'conflict' => [
        'strategy' => 'wait', // or 'reject'
        'wait_timeout' => 10,
        'retry_interval' => 100,
    ],

    'fingerprint' => [
        'enabled' => true,
        'algorithm' => 'sha256',
        'include_path' => true,
        'include_method' => true,
        'include_body' => true,
        'exclude_fields' => ['timestamp', 'nonce'],
    ],

    'scope' => [
        'enabled' => true,
        'resolver' => 'user', // user, tenant, ip, or callable
    ],

    'logging' => [
        'enabled' => true,
        'log_hits' => true,
        'log_conflicts' => true,
    ],
];
```

---

## Testing

```php
use Grazulex\ApiIdempotency\Facades\Idempotency;

public function test_idempotency(): void
{
    Idempotency::fake();

    // Your test code...

    Idempotency::assertStored('expected_key');
    Idempotency::assertReplayed('expected_key');
    Idempotency::assertStoredCount(5);
}
```

Integration testing:

```php
public function test_payment_is_idempotent(): void
{
    $key = 'test_key_' . uniqid();
    $payload = ['amount' => 9999];

    $response1 = $this->postJson('/api/payments', $payload, [
        'Idempotency-Key' => $key,
    ]);

    $response1->assertStatus(201)
        ->assertHeader('X-Idempotent-Replayed', 'false');

    $response2 = $this->postJson('/api/payments', $payload, [
        'Idempotency-Key' => $key,
    ]);

    $response2->assertStatus(201)
        ->assertHeader('X-Idempotent-Replayed', 'true')
        ->assertJson($response1->json());
}
```

---

## Quality Tools

```bash
# Run tests
composer test

# Code style (Laravel Pint)
composer pint

# Static analysis (PHPStan Level 5)
composer analyse
```

---

## Error Responses

| Code | Status | Description |
|------|--------|-------------|
| `IDEMPOTENCY_KEY_MISSING` | 400 | Key required but not provided |
| `IDEMPOTENCY_KEY_INVALID` | 400 | Key format invalid |
| `IDEMPOTENCY_PAYLOAD_MISMATCH` | 422 | Same key, different payload |
| `IDEMPOTENCY_CONFLICT` | 409 | Request in progress with same key |

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

## Support This Package

Laravel API Idempotency is free, open source and maintained on my own time. If it saves you hours, here is how you can give back:

- ⭐ **Star the repository** — it helps other developers find it
- 🐦 **Share it** with your team and network
- 💖 **[Sponsor on GitHub](https://github.com/sponsors/Grazulex)**, **[buy me a coffee](https://buymeacoffee.com/grazulex)** or **[donate via PayPal](https://paypal.me/strauven)** — every contribution funds maintenance, new features and Laravel upgrades

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

## See Also

- [laravel-apiroute](https://github.com/Grazulex/laravel-apiroute) - API versioning lifecycle management
- [laravel-api-kit](https://github.com/Grazulex/laravel-api-kit) - API-only Laravel starter kit
