<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Contracts;

use Illuminate\Http\Request;

interface ScopeResolverInterface
{
    /**
     * Resolve the scope for the given request.
     */
    public function resolve(Request $request): ?string;
}
