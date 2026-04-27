<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Event\HealthStatusChangedEvent;
use App\Event\ThresholdExceededEvent;
use App\Redis\RedisClientInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class HealthStatusTrackerService implements HealthStatusTrackerServiceInterface
{
    private const REDIS_KEY_PREFIX = 'health_status:';
    private const STATUS_TTL = 3600;

    public function __construct(
        private RedisClientInterface $redisClient,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function trackHealthStatus(string $host, string $newStatus): void
    {
        $key = self::REDIS_KEY_PREFIX . $this->normalizeHostKey($host);
        $oldStatus = $this->redisClient->get($key);

        if ($oldStatus !== null && $oldStatus !== $newStatus) {
            $this->eventDispatcher->dispatch(new HealthStatusChangedEvent(
                $host,
                $oldStatus,
                $newStatus,
            ));
        }

        $this->redisClient->setex($key, self::STATUS_TTL, $newStatus);
    }

    public function trackThreshold(string $host, string $metric, float $value, float $threshold): void
    {
        if ($value > $threshold) {
            $this->eventDispatcher->dispatch(new ThresholdExceededEvent(
                $host,
                $metric,
                $value,
                $threshold,
            ));
        }
    }

    public function getCurrentStatus(string $host): ?string
    {
        $key = self::REDIS_KEY_PREFIX . $this->normalizeHostKey($host);

        return $this->redisClient->get($key);
    }

    private function normalizeHostKey(string $host): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $host) ?? $host;
    }
}
