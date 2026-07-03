<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Redis\RedisClientInterface;
use App\Service\StatusService;
use App\Service\StatusServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StatusServiceTest extends KernelTestCase
{
    private StatusServiceInterface $statusService;
    private CacheItemPoolInterface $cache;
    private ?RedisClientInterface $redisClient = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->statusService = self::getContainer()->get(StatusServiceInterface::class);
        $this->cache = self::getContainer()->get(CacheItemPoolInterface::class);
        $this->redisClient = self::getContainer()->get(RedisClientInterface::class);
        $this->clearAllStatusData();
    }

    protected function tearDown(): void
    {
        $this->clearAllStatusData();
        parent::tearDown();
    }

    private function clearAllStatusData(): void
    {
        $this->cache->clear();

        if ($this->redisClient !== null) {
            $this->redisClient->del(StatusService::REDIS_KEY_START_TIME);
            $this->redisClient->del(StatusService::REDIS_KEY_REQUESTS_TOTAL);
            $this->redisClient->del(StatusService::REDIS_KEY_ACTIVE_CONNECTIONS);
        }
    }

    #[Test]
    public function getUptimeSecondsReturnsZeroWhenNoStartTimeSet(): void
    {
        $result = $this->statusService->getUptimeSeconds();

        self::assertSame(0, $result);
    }

    #[Test]
    public function setServerStartTimeAndGetUptimeWorks(): void
    {
        $startTime = time() - 3600;
        $this->statusService->setServerStartTime($startTime);

        $uptime = $this->statusService->getUptimeSeconds();

        self::assertGreaterThanOrEqual(3600, $uptime);
        self::assertLessThanOrEqual(3602, $uptime);
    }

    #[Test]
    public function setServerStartTimeDoesNotOverwriteExistingValue(): void
    {
        $originalStartTime = time() - 7200;
        $this->statusService->setServerStartTime($originalStartTime);

        $newStartTime = time() - 100;
        $this->statusService->setServerStartTime($newStartTime);

        $uptime = $this->statusService->getUptimeSeconds();

        self::assertGreaterThanOrEqual(7200, $uptime);
    }

    #[Test]
    public function getTotalRequestsProxiedReturnsZeroInitially(): void
    {
        $result = $this->statusService->getTotalRequestsProxied();

        self::assertSame(0, $result);
    }

    #[Test]
    public function incrementRequestCounterWorks(): void
    {
        self::assertSame(0, $this->statusService->getTotalRequestsProxied());

        $this->statusService->incrementRequestCounter();
        self::assertSame(1, $this->statusService->getTotalRequestsProxied());

        $this->statusService->incrementRequestCounter();
        self::assertSame(2, $this->statusService->getTotalRequestsProxied());

        $this->statusService->incrementRequestCounter();
        self::assertSame(3, $this->statusService->getTotalRequestsProxied());
    }

    #[Test]
    public function getActiveConnectionsReturnsZeroInitially(): void
    {
        $result = $this->statusService->getActiveConnections();

        self::assertSame(0, $result);
    }

    #[Test]
    public function updateActiveConnectionsWorks(): void
    {
        $this->statusService->updateActiveConnections(42);

        self::assertSame(42, $this->statusService->getActiveConnections());

        $this->statusService->updateActiveConnections(100);

        self::assertSame(100, $this->statusService->getActiveConnections());
    }

    #[Test]
    public function resetStartTimeWorks(): void
    {
        $this->statusService->setServerStartTime(time() - 3600);
        self::assertGreaterThan(0, $this->statusService->getUptimeSeconds());

        $this->statusService->resetStartTime();

        self::assertSame(0, $this->statusService->getUptimeSeconds());
    }

    #[Test]
    public function getStatusReturnsCompleteStatusArray(): void
    {
        $startTime = time() - 7200;
        $this->statusService->setServerStartTime($startTime);
        $this->statusService->incrementRequestCounter();
        $this->statusService->incrementRequestCounter();
        $this->statusService->updateActiveConnections(10);

        $result = $this->statusService->getStatus();

        self::assertArrayHasKey('uptime_seconds', $result);
        self::assertArrayHasKey('uptime_human', $result);
        self::assertArrayHasKey('total_requests_proxied', $result);
        self::assertArrayHasKey('active_connections', $result);
        self::assertArrayHasKey('timestamp', $result);

        self::assertGreaterThanOrEqual(7200, $result['uptime_seconds']);
        self::assertStringContainsString('h', $result['uptime_human']);
        self::assertSame(2, $result['total_requests_proxied']);
        self::assertSame(10, $result['active_connections']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $result['timestamp']
        );
    }

    #[Test]
    public function formatUptimeFormatsSecondsOnly(): void
    {
        $this->statusService->setServerStartTime(time() - 45);

        $result = $this->statusService->getStatus();

        self::assertMatchesRegularExpression('/^\d+s$/', $result['uptime_human']);
    }

    #[Test]
    public function formatUptimeFormatsMinutesAndSeconds(): void
    {
        $this->statusService->setServerStartTime(time() - 125);

        $result = $this->statusService->getStatus();

        self::assertMatchesRegularExpression('/^\d+m \d+s$/', $result['uptime_human']);
    }

    #[Test]
    public function formatUptimeFormatsHoursMinutesAndSeconds(): void
    {
        $this->statusService->setServerStartTime(time() - 3665);

        $result = $this->statusService->getStatus();

        self::assertMatchesRegularExpression('/^\d+h \d+m \d+s$/', $result['uptime_human']);
    }

    #[Test]
    public function formatUptimeFormatsDaysHoursMinutesAndSeconds(): void
    {
        $this->statusService->setServerStartTime(time() - 90065);

        $result = $this->statusService->getStatus();

        self::assertMatchesRegularExpression('/^\d+d \d+h \d+m \d+s$/', $result['uptime_human']);
    }
}
