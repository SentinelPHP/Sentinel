<?php

declare(strict_types=1);

namespace App\Redis;

/**
 * Adaptive Redis client that automatically selects the appropriate implementation
 * based on the runtime environment (Swoole coroutine vs standard PHP).
 * 
 * Uses PooledRedisClient when running inside a Swoole coroutine for better performance,
 * and falls back to the standard RedisClient when running outside (e.g., Symfony Messenger).
 */
final class AdaptiveRedisClient implements RedisClientInterface
{
    public function __construct(
        private readonly RedisClient $standardClient,
        private readonly ?SwooleRedisPool $swoolePool = null,
    ) {
    }

    public function incr(string $key): int
    {
        return $this->getClient()->incr($key);
    }

    public function get(string $key): ?string
    {
        return $this->getClient()->get($key);
    }

    public function set(string $key, string $value): void
    {
        $this->getClient()->set($key, $value);
    }

    public function setex(string $key, int $ttl, string $value): void
    {
        $this->getClient()->setex($key, $ttl, $value);
    }

    public function setnx(string $key, string $value): bool
    {
        return $this->getClient()->setnx($key, $value);
    }

    public function del(string $key): bool
    {
        return $this->getClient()->del($key);
    }

    private function getClient(): RedisClientInterface
    {
        if ($this->isInSwooleCoroutine() && $this->swoolePool !== null) {
            return new PooledRedisClient($this->swoolePool);
        }

        return $this->standardClient;
    }

    /**
     * Check if we're currently running inside a Swoole coroutine.
     */
    private function isInSwooleCoroutine(): bool
    {
        if (!extension_loaded('swoole')) {
            return false;
        }

        if (!class_exists(\Swoole\Coroutine::class)) {
            return false;
        }

        // getCid() returns -1 when not in a coroutine
        return \Swoole\Coroutine::getCid() > 0;
    }
}
