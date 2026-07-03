<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Http\HttpClientException;
use App\Http\HttpClientInterface;
use App\Http\HttpResponse;
use App\Service\HealthCheckService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

#[CoversClass(HealthCheckService::class)]
#[AllowMockObjectsWithoutExpectations]
final class HealthCheckServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private CacheItemPoolInterface&MockObject $cache;
    private HttpClientInterface&MockObject $httpClient;
    private HealthCheckService $healthCheckService;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);

        $this->healthCheckService = new HealthCheckService(
            $this->connection,
            $this->cache,
            $this->httpClient,
            'https://httpbin.org/status/200',
        );
    }

    #[Test]
    public function checkDatabaseReturnsOkWhenConnectionSucceeds(): void
    {
        $this->connection
            ->expects(self::once())
            ->method('executeQuery')
            ->with('SELECT 1');

        $result = $this->healthCheckService->checkDatabase();

        self::assertSame('ok', $result['status']);
        self::assertArrayHasKey('latency_ms', $result);
        self::assertIsInt($result['latency_ms'] ?? null);
    }

    #[Test]
    public function checkDatabaseReturnsErrorWhenConnectionFails(): void
    {
        $this->connection
            ->expects(self::once())
            ->method('executeQuery')
            ->willThrowException(new \Exception('Connection refused'));

        $result = $this->healthCheckService->checkDatabase();

        self::assertSame('error', $result['status']);
        self::assertArrayHasKey('message', $result);
        self::assertSame('Connection refused', $result['message'] ?? '');
        self::assertArrayNotHasKey('latency_ms', $result);
    }

    #[Test]
    public function checkRedisReturnsOkWhenCacheWorks(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('get')->willReturn('ok');

        $this->cache
            ->expects(self::exactly(2))
            ->method('getItem')
            ->willReturn($cacheItem);

        $this->cache
            ->expects(self::once())
            ->method('save')
            ->with($cacheItem)
            ->willReturn(true);

        $this->cache
            ->expects(self::once())
            ->method('deleteItem');

        $result = $this->healthCheckService->checkRedis();

        self::assertSame('ok', $result['status']);
        self::assertArrayHasKey('latency_ms', $result);
    }

    #[Test]
    public function checkRedisReturnsErrorWhenCacheFails(): void
    {
        $this->cache
            ->expects(self::once())
            ->method('getItem')
            ->willThrowException(new \Exception('Redis connection failed'));

        $result = $this->healthCheckService->checkRedis();

        self::assertSame('error', $result['status']);
        self::assertArrayHasKey('message', $result);
        self::assertSame('Redis connection failed', $result['message'] ?? '');
    }

    #[Test]
    public function checkRedisReturnsErrorWhenVerificationFails(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('get')->willReturn('wrong_value');

        $this->cache
            ->expects(self::exactly(2))
            ->method('getItem')
            ->willReturn($cacheItem);

        $this->cache
            ->expects(self::once())
            ->method('save')
            ->willReturn(true);

        $result = $this->healthCheckService->checkRedis();

        self::assertSame('error', $result['status']);
        self::assertArrayHasKey('message', $result);
        self::assertSame('Cache read/write verification failed', $result['message'] ?? '');
    }

    #[Test]
    public function checkOutboundReturnsOkWhenRequestSucceeds(): void
    {
        $response = new HttpResponse(200, [], '');

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('HEAD', 'https://httpbin.org/status/200', [], null)
            ->willReturn($response);

        $result = $this->healthCheckService->checkOutbound();

        self::assertSame('ok', $result['status']);
        self::assertArrayHasKey('latency_ms', $result);
        self::assertArrayHasKey('url', $result);
        self::assertSame('https://httpbin.org/status/200', $result['url'] ?? '');
    }

    #[Test]
    public function checkOutboundReturnsOkFor3xxResponses(): void
    {
        $response = new HttpResponse(301, [], '');

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($response);

        $result = $this->healthCheckService->checkOutbound();

        self::assertSame('ok', $result['status']);
    }

    #[Test]
    public function checkOutboundReturnsErrorFor4xxResponses(): void
    {
        $response = new HttpResponse(404, [], '');

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($response);

        $result = $this->healthCheckService->checkOutbound();

        self::assertSame('error', $result['status']);
        self::assertArrayHasKey('message', $result);
        self::assertStringContainsString('404', $result['message'] ?? '');
    }

    #[Test]
    public function checkOutboundReturnsErrorWhenRequestFails(): void
    {
        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willThrowException(HttpClientException::connectionFailed('httpbin.org', 443, 'Connection timeout'));

        $result = $this->healthCheckService->checkOutbound();

        self::assertSame('error', $result['status']);
        self::assertArrayHasKey('message', $result);
        self::assertArrayHasKey('url', $result);
        self::assertStringContainsString('Connection timeout', $result['message'] ?? '');
        self::assertSame('https://httpbin.org/status/200', $result['url'] ?? '');
    }

    #[Test]
    public function getHealthStatusReturnsOkWhenAllChecksPass(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('get')->willReturn('ok');

        $this->connection->method('executeQuery');
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);
        $this->httpClient->method('request')->willReturn(new HttpResponse(200, [], ''));

        $result = $this->healthCheckService->getHealthStatus();

        self::assertSame('ok', $result['status']);
        self::assertArrayHasKey('timestamp', $result);
        self::assertArrayHasKey('checks', $result);
        self::assertSame('ok', $result['checks']['database']['status']);
        self::assertSame('ok', $result['checks']['redis']['status']);
        self::assertSame('ok', $result['checks']['outbound']['status']);
    }

    #[Test]
    public function getHealthStatusReturnsDegradedWhenAnyCheckFails(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('get')->willReturn('ok');

        $this->connection->method('executeQuery')->willThrowException(new \Exception('DB down'));
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);
        $this->httpClient->method('request')->willReturn(new HttpResponse(200, [], ''));

        $result = $this->healthCheckService->getHealthStatus();

        self::assertSame('degraded', $result['status']);
        self::assertSame('error', $result['checks']['database']['status']);
        self::assertSame('ok', $result['checks']['redis']['status']);
        self::assertSame('ok', $result['checks']['outbound']['status']);
    }

    #[Test]
    public function getHealthStatusIncludesIso8601Timestamp(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('get')->willReturn('ok');

        $this->connection->method('executeQuery');
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);
        $this->httpClient->method('request')->willReturn(new HttpResponse(200, [], ''));

        $result = $this->healthCheckService->getHealthStatus();

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $result['timestamp']
        );
    }
}
