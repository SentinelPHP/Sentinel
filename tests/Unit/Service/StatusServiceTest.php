<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Redis\RedisClientInterface;
use App\Service\StatusService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

#[CoversClass(StatusService::class)]
#[AllowMockObjectsWithoutExpectations]
final class StatusServiceTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $cache;
    private StatusService $statusService;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->statusService = new StatusService($this->cache);
    }

    #[Test]
    public function getUptimeSecondsReturnsZeroWhenNoStartTimeSet(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->with('sentinel_server_start_time')
            ->willReturn($cacheItem);

        $result = $this->statusService->getUptimeSeconds();

        self::assertSame(0, $result);
    }

    #[Test]
    public function getUptimeSecondsReturnsCorrectUptime(): void
    {
        $startTime = time() - 3600;

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($startTime);

        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->with('sentinel_server_start_time')
            ->willReturn($cacheItem);

        $result = $this->statusService->getUptimeSeconds();

        self::assertGreaterThanOrEqual(3600, $result);
        self::assertLessThanOrEqual(3601, $result);
    }

    #[Test]
    public function getTotalRequestsProxiedReturnsZeroWhenNoCounter(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->with('sentinel_stats_requests_total')
            ->willReturn($cacheItem);

        $result = $this->statusService->getTotalRequestsProxied();

        self::assertSame(0, $result);
    }

    #[Test]
    public function getTotalRequestsProxiedReturnsCounterValue(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(12345);

        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->with('sentinel_stats_requests_total')
            ->willReturn($cacheItem);

        $result = $this->statusService->getTotalRequestsProxied();

        self::assertSame(12345, $result);
    }

    #[Test]
    public function getActiveConnectionsReturnsZeroWhenNoData(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->with('sentinel_stats_active_connections')
            ->willReturn($cacheItem);

        $result = $this->statusService->getActiveConnections();

        self::assertSame(0, $result);
    }

    #[Test]
    public function getActiveConnectionsReturnsStoredValue(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(42);

        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->with('sentinel_stats_active_connections')
            ->willReturn($cacheItem);

        $result = $this->statusService->getActiveConnections();

        self::assertSame(42, $result);
    }

    #[Test]
    public function setServerStartTimeSetsValueWhenNotAlreadySet(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->expects(self::once())->method('set')->with(1234567890);

        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->with('sentinel_server_start_time')
            ->willReturn($cacheItem);

        $this->cache
            ->expects(self::once())
            ->method('save')
            ->with($cacheItem);

        $this->statusService->setServerStartTime(1234567890);
    }

    #[Test]
    public function setServerStartTimeDoesNotOverwriteExistingValue(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->expects(self::never())->method('set');

        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->with('sentinel_server_start_time')
            ->willReturn($cacheItem);

        $this->cache
            ->expects(self::never())
            ->method('save');

        $this->statusService->setServerStartTime(1234567890);
    }

    #[Test]
    public function incrementRequestCounterIncrementsFromZero(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->expects(self::once())->method('set')->with(1);

        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->with('sentinel_stats_requests_total')
            ->willReturn($cacheItem);

        $this->cache
            ->expects(self::once())
            ->method('save')
            ->with($cacheItem);

        $this->statusService->incrementRequestCounter();
    }

    #[Test]
    public function incrementRequestCounterIncrementsExistingValue(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(100);
        $cacheItem->expects(self::once())->method('set')->with(101);

        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->with('sentinel_stats_requests_total')
            ->willReturn($cacheItem);

        $this->cache
            ->expects(self::once())
            ->method('save')
            ->with($cacheItem);

        $this->statusService->incrementRequestCounter();
    }

    #[Test]
    public function updateActiveConnectionsSetsValueWithExpiry(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects(self::once())->method('set')->with(50);
        $cacheItem->expects(self::once())->method('expiresAfter')->with(60);

        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->with('sentinel_stats_active_connections')
            ->willReturn($cacheItem);

        $this->cache
            ->expects(self::once())
            ->method('save')
            ->with($cacheItem);

        $this->statusService->updateActiveConnections(50);
    }

    #[Test]
    public function getStatusReturnsCompleteStatusArray(): void
    {
        $startTime = time() - 7200;

        $startTimeItem = $this->createMock(CacheItemInterface::class);
        $startTimeItem->method('isHit')->willReturn(true);
        $startTimeItem->method('get')->willReturn($startTime);

        $requestsItem = $this->createMock(CacheItemInterface::class);
        $requestsItem->method('isHit')->willReturn(true);
        $requestsItem->method('get')->willReturn(500);

        $connectionsItem = $this->createMock(CacheItemInterface::class);
        $connectionsItem->method('isHit')->willReturn(true);
        $connectionsItem->method('get')->willReturn(10);

        $this->cache
            ->method('getItem')
            ->willReturnCallback(function (string $key) use ($startTimeItem, $requestsItem, $connectionsItem) {
                return match ($key) {
                    'sentinel_server_start_time' => $startTimeItem,
                    'sentinel_stats_requests_total' => $requestsItem,
                    'sentinel_stats_active_connections' => $connectionsItem,
                    default => throw new \InvalidArgumentException("Unexpected key: $key"),
                };
            });

        $result = $this->statusService->getStatus();

        self::assertArrayHasKey('uptime_seconds', $result);
        self::assertArrayHasKey('uptime_human', $result);
        self::assertArrayHasKey('total_requests_proxied', $result);
        self::assertArrayHasKey('active_connections', $result);
        self::assertArrayHasKey('timestamp', $result);

        self::assertGreaterThanOrEqual(7200, $result['uptime_seconds']);
        self::assertStringContainsString('h', $result['uptime_human']);
        self::assertSame(500, $result['total_requests_proxied']);
        self::assertSame(10, $result['active_connections']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $result['timestamp']
        );
    }

    #[Test]
    public function formatUptimeFormatsSecondsOnly(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(time() - 45);

        $this->cache
            ->method('getItem')
            ->willReturn($cacheItem);

        $result = $this->statusService->getStatus();

        self::assertMatchesRegularExpression('/^\d+s$/', $result['uptime_human']);
    }

    #[Test]
    public function formatUptimeFormatsMinutesAndSeconds(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(time() - 125);

        $this->cache
            ->method('getItem')
            ->willReturn($cacheItem);

        $result = $this->statusService->getStatus();

        self::assertMatchesRegularExpression('/^\d+m \d+s$/', $result['uptime_human']);
    }

    #[Test]
    public function formatUptimeFormatsHoursMinutesAndSeconds(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(time() - 3665);

        $this->cache
            ->method('getItem')
            ->willReturn($cacheItem);

        $result = $this->statusService->getStatus();

        self::assertMatchesRegularExpression('/^\d+h \d+m \d+s$/', $result['uptime_human']);
    }

    #[Test]
    public function formatUptimeFormatsDaysHoursMinutesAndSeconds(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(time() - 90065);

        $this->cache
            ->method('getItem')
            ->willReturn($cacheItem);

        $result = $this->statusService->getStatus();

        self::assertMatchesRegularExpression('/^\d+d \d+h \d+m \d+s$/', $result['uptime_human']);
    }

    #[Test]
    public function resetStartTimeDeletesCacheItem(): void
    {
        $this->cache
            ->expects(self::once())
            ->method('deleteItem')
            ->with('sentinel_server_start_time');

        $this->statusService->resetStartTime();
    }

    #[Test]
    public function incrementRequestCounterUsesAtomicIncrWhenRedisClientAvailable(): void
    {
        $redisClient = $this->createMock(RedisClientInterface::class);
        $redisClient
            ->expects(self::once())
            ->method('incr')
            ->with('sentinel:stats:requests_total')
            ->willReturn(42);

        $this->cache
            ->expects(self::never())
            ->method('getItem');

        $statusService = new StatusService($this->cache, $redisClient);
        $statusService->incrementRequestCounter();
    }

    #[Test]
    public function getTotalRequestsProxiedUsesRedisClientWhenAvailable(): void
    {
        $redisClient = $this->createMock(RedisClientInterface::class);
        $redisClient
            ->expects(self::once())
            ->method('get')
            ->with('sentinel:stats:requests_total')
            ->willReturn('12345');

        $this->cache
            ->expects(self::never())
            ->method('getItem');

        $statusService = new StatusService($this->cache, $redisClient);
        $result = $statusService->getTotalRequestsProxied();

        self::assertSame(12345, $result);
    }

    #[Test]
    public function getTotalRequestsProxiedReturnsZeroWhenRedisKeyNotExists(): void
    {
        $redisClient = $this->createMock(RedisClientInterface::class);
        $redisClient
            ->expects(self::once())
            ->method('get')
            ->with('sentinel:stats:requests_total')
            ->willReturn(null);

        $statusService = new StatusService($this->cache, $redisClient);
        $result = $statusService->getTotalRequestsProxied();

        self::assertSame(0, $result);
    }

}
