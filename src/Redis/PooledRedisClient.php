<?php

declare(strict_types=1);

namespace App\Redis;

/**
 * Redis client that uses connection pooling for better performance under Swoole.
 */
final class PooledRedisClient implements RedisClientInterface
{
    public function __construct(
        private readonly SwooleRedisPool $pool,
    ) {
    }

    public function incr(string $key): int
    {
        return $this->execute(function (\Redis $redis) use ($key): int {
            $result = $redis->incr($key);
            return $result !== false ? $result : 0;
        });
    }

    public function get(string $key): ?string
    {
        return $this->execute(function (\Redis $redis) use ($key): ?string {
            $result = $redis->get($key);
            if ($result === false) {
                return null;
            }
            /** @var string $stringResult */
            $stringResult = $result;
            return $stringResult;
        });
    }

    public function set(string $key, string $value): void
    {
        $this->execute(fn (\Redis $redis) => $redis->set($key, $value));
    }

    public function setex(string $key, int $ttl, string $value): void
    {
        $this->execute(fn (\Redis $redis) => $redis->setex($key, $ttl, $value));
    }

    public function setnx(string $key, string $value): bool
    {
        return $this->execute(fn (\Redis $redis) => (bool) $redis->setnx($key, $value));
    }

    public function del(string $key): bool
    {
        return $this->execute(fn (\Redis $redis) => $redis->del($key) > 0);
    }

    /**
     * @template T
     * @param callable(\Redis): T $callback
     * @return T
     */
    private function execute(callable $callback): mixed
    {
        $connection = $this->pool->get();

        try {
            return $callback($connection);
        } finally {
            $this->pool->release($connection);
        }
    }
}
