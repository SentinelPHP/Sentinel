<?php

declare(strict_types=1);

namespace App\Redis;

use Swoole\Coroutine\Channel;

/**
 * Connection pool for Redis using Swoole coroutines.
 * Reuses connections across requests to avoid connection overhead.
 */
final class SwooleRedisPool
{
    private ?Channel $pool = null;
    private int $currentSize = 0;

    public function __construct(
        private readonly string $redisUrl,
        private readonly int $poolSize = 10,
        private readonly float $timeout = 3.0,
    ) {
    }

    public function get(): \Redis
    {
        $this->initPool();

        /** @var Channel $pool */
        $pool = $this->pool;

        if ($pool->isEmpty() && $this->currentSize < $this->poolSize) {
            return $this->createConnection();
        }

        $connection = $pool->pop($this->timeout);

        if ($connection === false) {
            if ($this->currentSize < $this->poolSize) {
                return $this->createConnection();
            }
            throw new \RuntimeException('Redis connection pool exhausted');
        }

        if (!$connection instanceof \Redis) {
            throw new \RuntimeException('Invalid connection type in pool');
        }

        if (!$this->isConnectionValid($connection)) {
            $this->currentSize--;
            return $this->get();
        }

        return $connection;
    }

    public function release(\Redis $connection): void
    {
        if ($this->pool === null) {
            return;
        }

        if (!$this->isConnectionValid($connection)) {
            $this->currentSize--;
            return;
        }

        if (!$this->pool->isFull()) {
            $this->pool->push($connection);
        } else {
            $connection->close();
            $this->currentSize--;
        }
    }

    public function close(): void
    {
        if ($this->pool === null) {
            return;
        }

        while (!$this->pool->isEmpty()) {
            $connection = $this->pool->pop(0.1);
            if ($connection instanceof \Redis) {
                $connection->close();
            }
        }

        $this->pool->close();
        $this->pool = null;
        $this->currentSize = 0;
    }

    private function initPool(): void
    {
        if ($this->pool === null) {
            $this->pool = new Channel($this->poolSize);
        }
    }

    private function createConnection(): \Redis
    {
        $parsed = parse_url($this->redisUrl);

        if ($parsed === false) {
            throw new \RuntimeException("Invalid Redis URL: {$this->redisUrl}");
        }

        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 6379;
        $password = $parsed['pass'] ?? null;
        $database = isset($parsed['path']) ? (int) ltrim($parsed['path'], '/') : 0;

        $redis = new \Redis();

        if (!$redis->connect($host, $port, $this->timeout)) {
            throw new \RuntimeException("Failed to connect to Redis at {$host}:{$port}");
        }

        if ($password !== null) {
            $redis->auth($password);
        }

        if ($database > 0) {
            $redis->select($database);
        }

        $this->currentSize++;

        return $redis;
    }

    private function isConnectionValid(\Redis $connection): bool
    {
        try {
            return $connection->ping() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getPoolSize(): int
    {
        return $this->poolSize;
    }

    public function getCurrentSize(): int
    {
        return $this->currentSize;
    }

    public function getAvailableCount(): int
    {
        $length = $this->pool?->length();

        return is_int($length) ? $length : 0;
    }
}
