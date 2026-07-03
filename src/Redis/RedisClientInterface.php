<?php

declare(strict_types=1);

namespace App\Redis;

interface RedisClientInterface
{
    /**
     * Atomically increment a key's integer value by 1.
     * If the key does not exist, it is set to 0 before performing the operation.
     *
     * @return int The value after the increment
     */
    public function incr(string $key): int;

    /**
     * Get the value of a key.
     *
     * @return string|null The value, or null if key doesn't exist
     */
    public function get(string $key): ?string;

    /**
     * Set a key to hold a string value.
     */
    public function set(string $key, string $value): void;

    /**
     * Set a key with an expiration time in seconds.
     */
    public function setex(string $key, int $ttl, string $value): void;

    /**
     * Set a key only if it does not already exist.
     *
     * @return bool True if the key was set, false if it already existed
     */
    public function setnx(string $key, string $value): bool;

    /**
     * Delete a key.
     *
     * @return bool True if the key was deleted, false if it didn't exist
     */
    public function del(string $key): bool;
}
