<?php

namespace App\Exceptions;

class RateLimitException extends \RuntimeException
{
    public function __construct(
        private readonly int $retryAfter,
        string $provider = '',
    ) {
        parent::__construct("Rate limit hit on {$provider}. Retry after {$retryAfter}s.");
    }

    public function getRetryAfter(): int
    {
        return max(1, $this->retryAfter);
    }
}
