<?php

declare(strict_types=1);

namespace App\Security;

use App\Redis\RedisClientInterface;

final class RateLimiter implements RateLimiterInterface
{
    private const string KEY_PREFIX = 'sentinel:rate_limit:';
    private const int DEFAULT_MAX_REQUESTS = 1000;
    private const int DEFAULT_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly ?RedisClientInterface $redisClient = null,
        private readonly int $maxRequests = self::DEFAULT_MAX_REQUESTS,
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
    ) {
    }

    public function isAllowed(string $identifier): RateLimitResult
    {
        if ($this->redisClient === null) {
            return RateLimitResult::allowed($this->maxRequests, $this->maxRequests);
        }

        $key = self::KEY_PREFIX . $identifier;
        $current = (int) ($this->redisClient->get($key) ?? 0);

        if ($current >= $this->maxRequests) {
            return RateLimitResult::denied(
                $this->maxRequests,
                0,
                $this->windowSeconds
            );
        }

        $newCount = $this->redisClient->incr($key);

        if ($newCount === 1) {
            $this->redisClient->setex($key, $this->windowSeconds, (string) $newCount);
        }

        return RateLimitResult::allowed(
            $this->maxRequests,
            max(0, $this->maxRequests - $newCount)
        );
    }

    public function isAuthFailureAllowed(string $ip): RateLimitResult
    {
        if ($this->redisClient === null) {
            return RateLimitResult::allowed(10, 10);
        }

        $key = self::KEY_PREFIX . 'auth_fail:' . $ip;
        $maxFailures = 10;
        $windowSeconds = 300;

        $current = (int) ($this->redisClient->get($key) ?? 0);

        if ($current >= $maxFailures) {
            return RateLimitResult::denied($maxFailures, 0, $windowSeconds);
        }

        return RateLimitResult::allowed($maxFailures, $maxFailures - $current);
    }

    public function recordAuthFailure(string $ip): void
    {
        if ($this->redisClient === null) {
            return;
        }

        $key = self::KEY_PREFIX . 'auth_fail:' . $ip;
        $windowSeconds = 300;

        $newCount = $this->redisClient->incr($key);

        if ($newCount === 1) {
            $this->redisClient->setex($key, $windowSeconds, (string) $newCount);
        }
    }

    public function clearAuthFailures(string $ip): void
    {
        if ($this->redisClient === null) {
            return;
        }

        $key = self::KEY_PREFIX . 'auth_fail:' . $ip;
        $this->redisClient->del($key);
    }
}
