<?php

declare(strict_types=1);

namespace App\Security;

interface RateLimiterInterface
{
    /**
     * Check if a request from the given identifier is allowed.
     *
     * @param string $identifier Unique identifier (e.g., token ID, IP address)
     */
    public function isAllowed(string $identifier): RateLimitResult;

    /**
     * Check if an authentication failure from the given IP is allowed.
     */
    public function isAuthFailureAllowed(string $ip): RateLimitResult;

    /**
     * Record an authentication failure for rate limiting purposes.
     */
    public function recordAuthFailure(string $ip): void;

    /**
     * Clear authentication failures for an IP (e.g., after successful auth).
     */
    public function clearAuthFailures(string $ip): void;
}
