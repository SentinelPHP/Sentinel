<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dashboard;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Redis\RedisClientInterface;
use App\Repository\RequestLogRepositoryInterface;
use App\Service\AccessControl\AccessControlServiceInterface;
use App\Service\Dashboard\LatencyMetricsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LatencyMetricsService::class)]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class LatencyMetricsServiceTest extends TestCase
{
    private AccessControlServiceInterface&MockObject $accessControlService;
    private RequestLogRepositoryInterface&MockObject $requestLogRepository;
    private RedisClientInterface&MockObject $redisClient;
    private LatencyMetricsService $service;

    protected function setUp(): void
    {
        $this->accessControlService = $this->createMock(AccessControlServiceInterface::class);
        $this->requestLogRepository = $this->createMock(RequestLogRepositoryInterface::class);
        $this->redisClient = $this->createMock(RedisClientInterface::class);

        $this->service = new LatencyMetricsService(
            $this->accessControlService,
            $this->requestLogRepository,
            $this->redisClient,
        );
    }

    #[Test]
    public function getPercentilesReturnsEmptyWhenNoTokens(): void
    {
        $user = $this->createUser();

        $this->accessControlService
            ->expects(self::once())
            ->method('getAccessibleTokens')
            ->with($user)
            ->willReturn([]);

        $result = $this->service->getPercentiles($user, 'api.example.com');

        self::assertEquals(['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0, 'min' => 0, 'max' => 0], $result);
    }

    #[Test]
    public function getPercentilesReturnsDataFromRepository(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->requestLogRepository
            ->expects(self::once())
            ->method('getHostDetailedStats')
            ->willReturn([
                'host' => 'api.example.com',
                'avgLatencyMs' => 150,
                'p50LatencyMs' => 100,
                'p95LatencyMs' => 300,
                'p99LatencyMs' => 500,
                'requestCount' => 1000,
                'errorRate' => 0.5,
            ]);

        $this->requestLogRepository
            ->expects(self::once())
            ->method('getHostLatencyRange')
            ->willReturn(['min' => 10, 'max' => 800]);

        $result = $this->service->getPercentiles($user, 'api.example.com');

        self::assertEquals([
            'p50' => 100,
            'p95' => 300,
            'p99' => 500,
            'avg' => 150,
            'min' => 10,
            'max' => 800,
        ], $result);
    }

    #[Test]
    public function getPercentilesReturnsEmptyWhenNoStats(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->requestLogRepository
            ->method('getHostDetailedStats')
            ->willReturn(null);

        $result = $this->service->getPercentiles($user, 'api.example.com');

        self::assertEquals(['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0, 'min' => 0, 'max' => 0], $result);
    }

    #[Test]
    public function getRollingAveragesReturnsNullWhenNoTokens(): void
    {
        $user = $this->createUser();

        $this->accessControlService
            ->expects(self::once())
            ->method('getAccessibleTokens')
            ->willReturn([]);

        $result = $this->service->getRollingAverages($user, 'api.example.com');

        self::assertEquals(['1m' => null, '5m' => null, '1h' => null], $result);
    }

    #[Test]
    public function getRollingAveragesReturnsDataFromRedis(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->redisClient
            ->method('get')
            ->willReturnCallback(function (string $key) {
                return match (true) {
                    str_contains($key, ':1m') => '100',
                    str_contains($key, ':5m') => '150',
                    str_contains($key, ':1h') => '200',
                    default => null,
                };
            });

        $result = $this->service->getRollingAverages($user, 'api.example.com');

        self::assertEquals(['1m' => 100, '5m' => 150, '1h' => 200], $result);
    }

    #[Test]
    public function getTrendReturnsStableWhenNoTokens(): void
    {
        $user = $this->createUser();

        $this->accessControlService
            ->expects(self::once())
            ->method('getAccessibleTokens')
            ->willReturn([]);

        $result = $this->service->getTrend($user, 'api.example.com');

        self::assertEquals('stable', $result);
    }

    #[Test]
    public function getTrendReturnsStableWhenNotEnoughSamples(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->redisClient
            ->method('get')
            ->willReturn(json_encode([
                ['latency' => 100, 'timestamp' => time()],
                ['latency' => 110, 'timestamp' => time() - 1],
            ]));

        $result = $this->service->getTrend($user, 'api.example.com');

        self::assertEquals('stable', $result);
    }

    #[Test]
    public function getTrendReturnsImprovingWhenLatencyDecreasing(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $samples = [];
        for ($i = 0; $i < 10; $i++) {
            $samples[] = ['latency' => 100 + ($i * 5), 'timestamp' => time() - $i];
        }

        $this->redisClient
            ->method('get')
            ->willReturn(json_encode($samples));

        $result = $this->service->getTrend($user, 'api.example.com');

        self::assertEquals('improving', $result);
    }

    #[Test]
    public function getTrendReturnsDegradingWhenLatencyIncreasing(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $samples = [];
        for ($i = 0; $i < 10; $i++) {
            $samples[] = ['latency' => 200 - ($i * 5), 'timestamp' => time() - $i];
        }

        $this->redisClient
            ->method('get')
            ->willReturn(json_encode($samples));

        $result = $this->service->getTrend($user, 'api.example.com');

        self::assertEquals('degrading', $result);
    }

    #[Test]
    public function getLatencyTimeSeriesReturnsEmptyWhenNoTokens(): void
    {
        $user = $this->createUser();

        $this->accessControlService
            ->expects(self::once())
            ->method('getAccessibleTokens')
            ->willReturn([]);

        $result = $this->service->getLatencyTimeSeries(
            $user,
            'api.example.com',
            new \DateTimeImmutable('-1 hour')
        );

        self::assertEmpty($result);
    }

    #[Test]
    public function getLatencyComparisonReturnsEmptyWhenNoTokens(): void
    {
        $user = $this->createUser();

        $this->accessControlService
            ->expects(self::once())
            ->method('getAccessibleTokens')
            ->willReturn([]);

        $result = $this->service->getLatencyComparison(
            $user,
            'api.example.com',
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable('-25 hours'),
            new \DateInterval('PT1H')
        );

        $expected = [
            'current' => ['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0],
            'baseline' => ['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0],
            'change' => ['p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0, 'avg' => 0.0],
        ];

        self::assertEquals($expected, $result);
    }

    #[Test]
    public function getLatencyComparisonCalculatesChangeCorrectly(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $callCount = 0;
        $this->requestLogRepository
            ->method('getHostDetailedStats')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return [
                        'host' => 'api.example.com',
                        'avgLatencyMs' => 200,
                        'p50LatencyMs' => 150,
                        'p95LatencyMs' => 400,
                        'p99LatencyMs' => 600,
                        'requestCount' => 1000,
                        'errorRate' => 0.5,
                    ];
                }

                return [
                    'host' => 'api.example.com',
                    'avgLatencyMs' => 100,
                    'p50LatencyMs' => 100,
                    'p95LatencyMs' => 200,
                    'p99LatencyMs' => 300,
                    'requestCount' => 800,
                    'errorRate' => 0.3,
                ];
            });

        $result = $this->service->getLatencyComparison(
            $user,
            'api.example.com',
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable('-25 hours'),
            new \DateInterval('PT1H')
        );

        self::assertEquals(150, $result['current']['p50']);
        self::assertEquals(100, $result['baseline']['p50']);
        self::assertEquals(50.0, $result['change']['p50']);
        self::assertEquals(100.0, $result['change']['avg']);
    }

    #[Test]
    public function recordLatencySampleStoresInRedis(): void
    {
        $this->redisClient
            ->expects(self::atLeast(1))
            ->method('get')
            ->willReturn(null);

        $this->redisClient
            ->expects(self::atLeast(4))
            ->method('setex');

        $this->service->recordLatencySample('api.example.com', 150);
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setPassword('hashed');

        return $user;
    }

    private function createToken(): ApiToken
    {
        $token = new ApiToken();
        $token->setName('Test Token');
        $token->setTokenHash('hash');
        $token->setAllowedTargets(['api.example.com']);

        return $token;
    }
}
