<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Http\Middleware;

use Closure;
use Grazulex\ApiIdempotency\Events\IdempotentConflictDetected;
use Grazulex\ApiIdempotency\Events\IdempotentPayloadMismatch;
use Grazulex\ApiIdempotency\Events\IdempotentRequestProcessed;
use Grazulex\ApiIdempotency\Events\IdempotentRequestReplayed;
use Grazulex\ApiIdempotency\Exceptions\ConflictException;
use Grazulex\ApiIdempotency\Exceptions\MissingKeyException;
use Grazulex\ApiIdempotency\Exceptions\PayloadMismatchException;
use Grazulex\ApiIdempotency\IdempotencyManager;
use Grazulex\ApiIdempotency\Support\IdempotencyKey;
use Grazulex\ApiIdempotency\Support\IdempotencyRecord;
use Grazulex\ApiIdempotency\Support\PayloadFingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class IdempotentMiddleware
{
    protected ?int $customTtl = null;

    protected bool $requireKey = false;

    protected ?string $customScope = null;

    public function __construct(
        protected IdempotencyManager $manager,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (SymfonyResponse)  $next
     */
    public function handle(Request $request, Closure $next, string ...$options): SymfonyResponse
    {
        $this->parseOptions($options);

        // Check if idempotency is enabled
        if (! $this->manager->isEnabled()) {
            return $next($request);
        }

        // Check if method should be handled
        if (! $this->shouldHandleMethod($request)) {
            return $next($request);
        }

        // Get idempotency key from header
        $headerName = $this->manager->getHeaderName();
        $key = $request->header($headerName);

        // Check if key is required
        if ($key === null) {
            if ($this->requireKey || $this->manager->isKeyRequired()) {
                throw new MissingKeyException;
            }

            return $next($request);
        }

        // Validate key format
        IdempotencyKey::validate($key);

        // Resolve scope
        $scope = $this->resolveScope($request);

        // Generate fingerprint
        $fingerprint = $this->shouldFingerprint()
            ? PayloadFingerprint::generate($request)
            : null;

        // Check for existing record
        $existingRecord = $this->manager->get($key, $scope);

        if ($existingRecord !== null) {
            return $this->handleExistingRecord($existingRecord, $request, $key, $scope, $fingerprint);
        }

        // Try to acquire lock
        if (! $this->acquireLock($key, $scope, $request)) {
            throw new ConflictException(
                retryAfter: $this->manager->getConfig('conflict.wait_timeout', 10)
            );
        }

        try {
            // Execute the request
            /** @var SymfonyResponse $response */
            $response = $next($request);

            // Check if we should skip caching
            if ($this->manager->shouldSkip()) {
                $this->manager->resetSkip();
                $this->manager->unlock($key, $scope);

                return $response;
            }

            // Store the response
            if ($this->shouldStoreResponse($response)) {
                $this->manager->store($key, $response, $scope, $fingerprint, $request);

                event(new IdempotentRequestProcessed(
                    key: $key,
                    scope: $scope,
                    request: $request,
                    response: $response instanceof Response ? $response : new Response($response->getContent(), $response->getStatusCode(), $response->headers->all()),
                    fingerprint: $fingerprint ?? '',
                ));

                $this->logMiss($key, $scope);
            } else {
                $this->manager->unlock($key, $scope);
            }

            // Add response headers
            $response = $this->addResponseHeaders($response, $key, false);

            return $response;
        } catch (\Throwable $e) {
            // Unlock on error
            $this->manager->unlock($key, $scope);
            throw $e;
        }
    }

    protected function parseOptions(array $options): void
    {
        foreach ($options as $option) {
            if (str_starts_with($option, 'ttl=')) {
                $this->customTtl = (int) substr($option, 4);
            } elseif ($option === 'required') {
                $this->requireKey = true;
            } elseif (str_starts_with($option, 'scope=')) {
                $this->customScope = substr($option, 6);
            }
        }
    }

    protected function shouldHandleMethod(Request $request): bool
    {
        $methods = $this->manager->getMethods();

        return in_array($request->method(), $methods, true);
    }

    protected function resolveScope(Request $request): ?string
    {
        if ($this->customScope !== null) {
            return $this->resolveCustomScope($request, $this->customScope);
        }

        if (! $this->manager->getConfig('scope.enabled', true)) {
            return null;
        }

        $resolver = $this->manager->getConfig('scope.resolver', 'user');

        if (is_callable($resolver)) {
            return $resolver($request);
        }

        return match ($resolver) {
            'user' => $request->user()?->getAuthIdentifier() ? (string) $request->user()->getAuthIdentifier() : null,
            'tenant' => $request->user()?->team_id ?? $request->user()?->tenant_id ?? null,
            'ip' => $request->ip(),
            default => null,
        };
    }

    protected function resolveCustomScope(Request $request, string $scopeType): ?string
    {
        return match ($scopeType) {
            'user' => $request->user()?->getAuthIdentifier() ? (string) $request->user()->getAuthIdentifier() : null,
            'tenant' => $request->user()?->team_id ?? $request->user()?->tenant_id ?? null,
            'ip' => $request->ip(),
            'team' => $request->route('team') ?? $request->user()?->team_id ?? null,
            default => $request->route($scopeType),
        };
    }

    protected function shouldFingerprint(): bool
    {
        return (bool) $this->manager->getConfig('fingerprint.enabled', true);
    }

    protected function handleExistingRecord(
        IdempotencyRecord $record,
        Request $request,
        string $key,
        ?string $scope,
        ?string $fingerprint,
    ): SymfonyResponse {
        // If still processing, handle conflict
        if ($record->isProcessing()) {
            return $this->handleConflict($key, $scope, $request);
        }

        // Verify fingerprint if enabled
        if ($fingerprint !== null && $record->fingerprint !== null) {
            if (! PayloadFingerprint::compare($fingerprint, $record->fingerprint)) {
                event(new IdempotentPayloadMismatch(
                    key: $key,
                    scope: $scope,
                    request: $request,
                    originalFingerprint: $record->fingerprint,
                    currentFingerprint: $fingerprint,
                ));

                throw new PayloadMismatchException(
                    originalFingerprint: $record->fingerprint,
                    currentFingerprint: $fingerprint,
                );
            }
        }

        // Return cached response
        $response = $record->toResponse();
        $response = $this->addResponseHeaders($response, $key, true, $record->createdAt);

        event(new IdempotentRequestReplayed(
            key: $key,
            scope: $scope,
            request: $request,
            originalRequestTime: $record->createdAt,
            hitCount: $record->hitCount + 1,
        ));

        $this->logHit($key, $scope);

        return $response;
    }

    protected function handleConflict(string $key, ?string $scope, Request $request): SymfonyResponse
    {
        $strategy = $this->manager->getConfig('conflict.strategy', 'wait');

        if ($strategy === 'reject') {
            event(new IdempotentConflictDetected(
                key: $key,
                scope: $scope,
                request: $request,
                waitTimeout: 0,
            ));

            throw new ConflictException;
        }

        // Wait strategy
        $waitTimeout = $this->manager->getConfig('conflict.wait_timeout', 10);
        $retryInterval = $this->manager->getConfig('conflict.retry_interval', 100);
        $startTime = microtime(true);

        while ((microtime(true) - $startTime) < $waitTimeout) {
            usleep($retryInterval * 1000);

            $record = $this->manager->get($key, $scope);

            if ($record === null) {
                // Record was deleted, proceed with request
                break;
            }

            if ($record->isCompleted()) {
                // Record completed, return cached response
                $response = $record->toResponse();

                return $this->addResponseHeaders($response, $key, true, $record->createdAt);
            }
        }

        event(new IdempotentConflictDetected(
            key: $key,
            scope: $scope,
            request: $request,
            waitTimeout: $waitTimeout,
        ));

        throw new ConflictException(retryAfter: $waitTimeout);
    }

    protected function acquireLock(string $key, ?string $scope, Request $request): bool
    {
        $timeout = $this->manager->getConfig('conflict.wait_timeout', 10);

        return $this->manager->lock($key, $scope, $timeout);
    }

    protected function shouldStoreResponse(SymfonyResponse $response): bool
    {
        // Only store successful responses and some client errors
        $statusCode = $response->getStatusCode();

        return $statusCode >= 200 && $statusCode < 500;
    }

    protected function addResponseHeaders(
        SymfonyResponse $response,
        string $key,
        bool $isReplayed,
        ?\DateTimeImmutable $originalTime = null,
    ): SymfonyResponse {
        $config = $this->manager->getConfig('headers', []);

        if ($config['echo_key'] ?? true) {
            $response->headers->set($this->manager->getHeaderName(), $key);
        }

        if ($config['replay_indicator'] ?? true) {
            $replayHeader = $config['replay_header'] ?? 'X-Idempotent-Replayed';
            $response->headers->set($replayHeader, $isReplayed ? 'true' : 'false');
        }

        if ($isReplayed && ($config['original_time'] ?? true) && $originalTime !== null) {
            $timeHeader = $config['original_time_header'] ?? 'X-Original-Request-Time';
            $response->headers->set($timeHeader, $originalTime->format('c'));
        }

        return $response;
    }

    protected function logHit(string $key, ?string $scope): void
    {
        $config = $this->manager->getConfig('logging', []);

        if (! ($config['enabled'] ?? true) || ! ($config['log_hits'] ?? true)) {
            return;
        }

        $channel = $config['channel'] ?? 'default';
        $level = $config['level'] ?? 'info';

        $logger = $channel === 'default' ? Log::getFacadeRoot() : Log::channel($channel);
        $logger->log($level, 'Idempotent request replayed', [
            'key' => $key,
            'scope' => $scope,
        ]);
    }

    protected function logMiss(string $key, ?string $scope): void
    {
        $config = $this->manager->getConfig('logging', []);

        if (! ($config['enabled'] ?? true) || ! ($config['log_misses'] ?? false)) {
            return;
        }

        $channel = $config['channel'] ?? 'default';
        $level = $config['level'] ?? 'info';

        $logger = $channel === 'default' ? Log::getFacadeRoot() : Log::channel($channel);
        $logger->log($level, 'Idempotent request processed', [
            'key' => $key,
            'scope' => $scope,
        ]);
    }
}
