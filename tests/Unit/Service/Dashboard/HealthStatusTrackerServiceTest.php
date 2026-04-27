<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dashboard;

use App\Event\HealthStatusChangedEvent;
use App\Event\ThresholdExceededEvent;
use App\Redis\RedisClientInterface;
use App\Service\Dashboard\HealthStatusTrackerService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

#[CoversClass(HealthStatusTrackerService::class)]
#[AllowMockObjectsWithoutExpectations]
final class HealthStatusTrackerServiceTest extends TestCase
{
    private RedisClientInterface&MockObject $redisClient;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private HealthStatusTrackerService $service;

    protected function setUp(): void
    {
        $this->redisClient = $this->createMock(RedisClientInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->service = new HealthStatusTrackerService(
            $this->redisClient,
            $this->eventDispatcher,
        );
    }

    #[Test]
    public function trackHealthStatusStoresStatusInRedis(): void
    {
        $this->redisClient->method('get')->willReturn(null);

        $this->redisClient->expects($this->once())
            ->method('setex')
            ->with(
                $this->stringContains('health_status:'),
                3600,
                'healthy'
            );

        $this->service->trackHealthStatus('api.example.com', 'healthy');
    }

    #[Test]
    public function trackHealthStatusDispatchesEventOnStatusChange(): void
    {
        $this->redisClient->method('get')->willReturn('healthy');

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (HealthStatusChangedEvent $event): bool {
                return $event->host === 'api.example.com'
                    && $event->oldStatus === 'healthy'
                    && $event->newStatus === 'degraded';
            }));

        $this->service->trackHealthStatus('api.example.com', 'degraded');
    }

    #[Test]
    public function trackHealthStatusDoesNotDispatchEventWhenStatusUnchanged(): void
    {
        $this->redisClient->method('get')->willReturn('healthy');

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->service->trackHealthStatus('api.example.com', 'healthy');
    }

    #[Test]
    public function trackHealthStatusDoesNotDispatchEventForFirstStatus(): void
    {
        $this->redisClient->method('get')->willReturn(null);

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->service->trackHealthStatus('api.example.com', 'healthy');
    }

    #[Test]
    public function trackThresholdDispatchesEventWhenExceeded(): void
    {
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (ThresholdExceededEvent $event): bool {
                return $event->host === 'api.example.com'
                    && $event->metric === 'latency_p99'
                    && $event->value === 1500.0
                    && $event->threshold === 1000.0;
            }));

        $this->service->trackThreshold('api.example.com', 'latency_p99', 1500.0, 1000.0);
    }

    #[Test]
    public function trackThresholdDoesNotDispatchEventWhenBelowThreshold(): void
    {
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->service->trackThreshold('api.example.com', 'latency_p99', 500.0, 1000.0);
    }

    #[Test]
    public function trackThresholdDoesNotDispatchEventWhenEqualToThreshold(): void
    {
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->service->trackThreshold('api.example.com', 'latency_p99', 1000.0, 1000.0);
    }

    #[Test]
    public function getCurrentStatusReturnsStoredStatus(): void
    {
        $this->redisClient->method('get')
            ->with($this->stringContains('health_status:'))
            ->willReturn('degraded');

        $status = $this->service->getCurrentStatus('api.example.com');

        self::assertSame('degraded', $status);
    }

    #[Test]
    public function getCurrentStatusReturnsNullWhenNotSet(): void
    {
        $this->redisClient->method('get')->willReturn(null);

        $status = $this->service->getCurrentStatus('api.example.com');

        self::assertNull($status);
    }

    #[Test]
    public function trackHealthStatusNormalizesHostKey(): void
    {
        $this->redisClient->method('get')->willReturn(null);

        $this->redisClient->expects($this->once())
            ->method('setex')
            ->with(
                $this->callback(function (string $key): bool {
                    return !str_contains($key, ':8080') || str_contains($key, '_8080');
                }),
                $this->anything(),
                $this->anything()
            );

        $this->service->trackHealthStatus('api.example.com:8080', 'healthy');
    }
}
