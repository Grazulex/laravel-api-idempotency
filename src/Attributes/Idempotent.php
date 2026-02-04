<?php

declare(strict_types=1);

namespace Grazulex\ApiIdempotency\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Idempotent
{
    public function __construct(
        public readonly ?int $ttl = null,
        public readonly bool $required = false,
        public readonly ?string $scope = null,
    ) {}

    /**
     * Get the middleware string representation.
     */
    public function toMiddleware(): string
    {
        $parts = ['idempotent'];
        $options = [];

        if ($this->ttl !== null) {
            $options[] = "ttl={$this->ttl}";
        }

        if ($this->required) {
            $options[] = 'required';
        }

        if ($this->scope !== null) {
            $options[] = "scope={$this->scope}";
        }

        if (! empty($options)) {
            $parts[] = implode(',', $options);
        }

        return implode(':', $parts);
    }
}
