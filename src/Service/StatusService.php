<?php

declare(strict_types=1);

namespace App\Service;

use App\Redis\RedisClientInterface;
use Psr\Cache\CacheItemPoolInterface;

final class StatusService implements StatusServiceInterface
{
    public const string REDIS_KEY_START_TIME = 'sentinel:server:start_time';
    public const string REDIS_KEY_REQUESTS_TOTAL = 'sentinel:stats:requests_total';
    public const string REDIS_KEY_ACTIVE_CONNECTIONS = 'sentinel:stats:active_connections';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ?RedisClientInterface $redisClient = null,
    ) {
    }

    /**
     * @return array{uptime_seconds: int, uptime_human: string, total_requests_proxied: int, active_connections: int, timestamp: string}
     */
    public function getStatus(): array
    {
        $uptimeSeconds = $this->getUptimeSeconds();

        return [
            'uptime_seconds' => $uptimeSeconds,
            'uptime_human' => $this->formatUptime($uptimeSeconds),
            'total_requests_proxied' => $this->getTotalRequestsProxied(),
            'active_connections' => $this->getActiveConnections(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    public function getUptimeSeconds(): int
    {
        $item = $this->cache->getItem($this->sanitizeKey(self::REDIS_KEY_START_TIME));

        if (!$item->isHit()) {
            return 0;
        }

        /** @var int|string $startTimeValue */
        $startTimeValue = $item->get();
        $startTime = (int) $startTimeValue;

        return max(0, time() - $startTime);
    }

    public function getTotalRequestsProxied(): int
    {
        if ($this->redisClient !== null) {
            $value = $this->redisClient->get(self::REDIS_KEY_REQUESTS_TOTAL);

            return $value !== null ? (int) $value : 0;
        }

        $item = $this->cache->getItem($this->sanitizeKey(self::REDIS_KEY_REQUESTS_TOTAL));

        if (!$item->isHit()) {
            return 0;
        }

        /** @var int|string $value */
        $value = $item->get();
        return (int) $value;
    }

    public function getActiveConnections(): int
    {
        $item = $this->cache->getItem($this->sanitizeKey(self::REDIS_KEY_ACTIVE_CONNECTIONS));

        if (!$item->isHit()) {
            return 0;
        }

        /** @var int|string $value */
        $value = $item->get();
        return (int) $value;
    }

    public function setServerStartTime(int $timestamp): void
    {
        $item = $this->cache->getItem($this->sanitizeKey(self::REDIS_KEY_START_TIME));

        if ($item->isHit()) {
            return;
        }

        $item->set($timestamp);
        $this->cache->save($item);
    }

    public function incrementRequestCounter(): void
    {
        if ($this->redisClient !== null) {
            $this->redisClient->incr(self::REDIS_KEY_REQUESTS_TOTAL);

            return;
        }

        $key = $this->sanitizeKey(self::REDIS_KEY_REQUESTS_TOTAL);
        $item = $this->cache->getItem($key);

        /** @var int|string|null $currentValue */
        $currentValue = $item->isHit() ? $item->get() : 0;
        $current = (int) $currentValue;
        $item->set($current + 1);
        $this->cache->save($item);
    }

    public function updateActiveConnections(int $count): void
    {
        $item = $this->cache->getItem($this->sanitizeKey(self::REDIS_KEY_ACTIVE_CONNECTIONS));
        $item->set($count);
        $item->expiresAfter(60);
        $this->cache->save($item);
    }

    public function resetStartTime(): void
    {
        $this->cache->deleteItem($this->sanitizeKey(self::REDIS_KEY_START_TIME));
    }

    private function formatUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];

        if ($days > 0) {
            $parts[] = "{$days}d";
        }

        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }

        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }

        if ($secs > 0 || empty($parts)) {
            $parts[] = "{$secs}s";
        }

        return implode(' ', $parts);
    }

    private function sanitizeKey(string $key): string
    {
        return str_replace(':', '_', $key);
    }
}
