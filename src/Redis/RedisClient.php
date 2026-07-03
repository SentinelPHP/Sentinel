<?php

declare(strict_types=1);

namespace App\Redis;

final class RedisClient implements RedisClientInterface
{
    private ?\Redis $redis = null;

    public function __construct(
        private readonly string $redisUrl,
    ) {
    }

    public function incr(string $key): int
    {
        $result = $this->getConnection()->incr($key);

        return $result !== false ? $result : 0;
    }

    public function get(string $key): ?string
    {
        $result = $this->getConnection()->get($key);

        if ($result === false) {
            return null;
        }

        /** @var string $stringResult */
        $stringResult = $result;
        return $stringResult;
    }

    public function set(string $key, string $value): void
    {
        $this->getConnection()->set($key, $value);
    }

    public function setex(string $key, int $ttl, string $value): void
    {
        $this->getConnection()->setex($key, $ttl, $value);
    }

    public function setnx(string $key, string $value): bool
    {
        return (bool) $this->getConnection()->setnx($key, $value);
    }

    public function del(string $key): bool
    {
        return $this->getConnection()->del($key) > 0;
    }

    private function getConnection(): \Redis
    {
        if ($this->redis === null) {
            $this->redis = $this->createConnection();
        }

        return $this->redis;
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

        if (!$redis->connect($host, $port)) {
            throw new \RuntimeException("Failed to connect to Redis at {$host}:{$port}");
        }

        if ($password !== null) {
            $redis->auth($password);
        }

        if ($database > 0) {
            $redis->select($database);
        }

        return $redis;
    }
}
