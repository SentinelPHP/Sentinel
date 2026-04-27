<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dashboard;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\SchemaDrift;
use App\Entity\User;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use App\Repository\RequestLogRepositoryInterface;
use App\Repository\SchemaDriftRepositoryInterface;
use App\Service\AccessControl\AccessControlServiceInterface;
use App\Service\Dashboard\ServiceHealthService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceHealthService::class)]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class ServiceHealthServiceTest extends TestCase
{
    private AccessControlServiceInterface&MockObject $accessControlService;
    private RequestLogRepositoryInterface&MockObject $requestLogRepository;
    private SchemaDriftRepositoryInterface&MockObject $schemaDriftRepository;
    private ServiceHealthService $service;

    protected function setUp(): void
    {
        $this->accessControlService = $this->createMock(AccessControlServiceInterface::class);
        $this->requestLogRepository = $this->createMock(RequestLogRepositoryInterface::class);
        $this->schemaDriftRepository = $this->createMock(SchemaDriftRepositoryInterface::class);

        $this->service = new ServiceHealthService(
            $this->accessControlService,
            $this->requestLogRepository,
            $this->schemaDriftRepository,
        );
    }

    #[Test]
    public function getAllServicesHealthReturnsEmptyWhenNoTokens(): void
    {
        $user = $this->createUser();

        $this->accessControlService
            ->expects(self::once())
            ->method('getAccessibleTokens')
            ->with($user)
            ->willReturn([]);

        $result = $this->service->getAllServicesHealth($user);

        self::assertEmpty($result);
    }

    #[Test]
    public function getAllServicesHealthReturnsServicesWithHealthStatus(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->requestLogRepository
            ->expects(self::once())
            ->method('getHostStats')
            ->willReturn([
                ['host' => 'api.example.com', 'avgLatencyMs' => 50, 'requestCount' => 100, 'errorRate' => 0.5],
                ['host' => 'slow.example.com', 'avgLatencyMs' => 800, 'requestCount' => 50, 'errorRate' => 2.0],
                ['host' => 'bad.example.com', 'avgLatencyMs' => 1500, 'requestCount' => 20, 'errorRate' => 10.0],
            ]);

        $this->schemaDriftRepository
            ->method('countBySeverityForHost')
            ->willReturnCallback(function (\DateTimeInterface $since, array $tokenIds, string $host) {
                return match ($host) {
                    'api.example.com' => ['critical' => 0, 'warning' => 0, 'info' => 0],
                    'slow.example.com' => ['critical' => 0, 'warning' => 2, 'info' => 1],
                    'bad.example.com' => ['critical' => 1, 'warning' => 0, 'info' => 0],
                    default => ['critical' => 0, 'warning' => 0, 'info' => 0],
                };
            });

        $result = $this->service->getAllServicesHealth($user);

        self::assertCount(3, $result);

        self::assertSame('api.example.com', $result[0]['host']);
        self::assertSame('green', $result[0]['status']);
        self::assertSame(50, $result[0]['avgLatencyMs']);
        self::assertSame(100, $result[0]['requestCount']);

        self::assertSame('slow.example.com', $result[1]['host']);
        self::assertSame('yellow', $result[1]['status']);

        self::assertSame('bad.example.com', $result[2]['host']);
        self::assertSame('red', $result[2]['status']);
    }

    #[Test]
    public function getServiceHealthByHostReturnsNullWhenNoTokens(): void
    {
        $user = $this->createUser();

        $this->accessControlService
            ->expects(self::once())
            ->method('getAccessibleTokens')
            ->with($user)
            ->willReturn([]);

        $result = $this->service->getServiceHealthByHost($user, 'api.example.com');

        self::assertNull($result);
    }

    #[Test]
    public function getServiceHealthByHostReturnsNullWhenHostNotFound(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->requestLogRepository
            ->expects(self::once())
            ->method('getHostDetailedStats')
            ->willReturn(null);

        $result = $this->service->getServiceHealthByHost($user, 'unknown.example.com');

        self::assertNull($result);
    }

    #[Test]
    public function getServiceHealthByHostReturnsDetailedStats(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();
        $schema = $this->createSchema($token);
        $drift = $this->createDrift($schema, $token);

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->requestLogRepository
            ->expects(self::once())
            ->method('getHostDetailedStats')
            ->willReturn([
                'host' => 'api.example.com',
                'avgLatencyMs' => 100,
                'p50LatencyMs' => 80,
                'p95LatencyMs' => 200,
                'p99LatencyMs' => 500,
                'requestCount' => 1000,
                'errorRate' => 0.5,
            ]);

        $this->requestLogRepository
            ->expects(self::once())
            ->method('getRecentRequestsByHost')
            ->willReturn([
                [
                    'id' => 'test-id',
                    'method' => 'GET',
                    'path' => '/api/users',
                    'statusCode' => 200,
                    'latencyMs' => 50,
                    'createdAt' => new \DateTimeImmutable(),
                ],
            ]);

        $this->schemaDriftRepository
            ->expects(self::once())
            ->method('countBySeverityForHost')
            ->willReturn(['critical' => 1, 'warning' => 2, 'info' => 3]);

        $this->schemaDriftRepository
            ->expects(self::once())
            ->method('findRecentByHost')
            ->willReturn([$drift]);

        $result = $this->service->getServiceHealthByHost($user, 'api.example.com');

        self::assertNotNull($result);
        self::assertSame('api.example.com', $result['host']);
        self::assertSame('red', $result['status']);
        self::assertSame(100, $result['avgLatencyMs']);
        self::assertSame(80, $result['p50LatencyMs']);
        self::assertSame(200, $result['p95LatencyMs']);
        self::assertSame(500, $result['p99LatencyMs']);
        self::assertSame(1000, $result['requestCount']);
        self::assertSame(0.5, $result['errorRate']);
        self::assertSame(1, $result['criticalDrifts']);
        self::assertSame(2, $result['warningDrifts']);
        self::assertSame(3, $result['infoDrifts']);
        self::assertCount(1, $result['recentRequests']);
        self::assertCount(1, $result['recentDrifts']);
    }

    #[Test]
    public function getHealthHistoryReturnsEmptyWhenNoTokens(): void
    {
        $user = $this->createUser();

        $this->accessControlService
            ->expects(self::once())
            ->method('getAccessibleTokens')
            ->with($user)
            ->willReturn([]);

        $result = $this->service->getHealthHistory($user, 'api.example.com', new \DateTimeImmutable('-24 hours'));

        self::assertEmpty($result);
    }

    #[Test]
    public function getHealthHistoryReturnsHistoryWithStatus(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->requestLogRepository
            ->expects(self::once())
            ->method('getHostHealthHistory')
            ->willReturn([
                ['hour' => '2024-01-01 10:00', 'avgLatencyMs' => 50, 'requestCount' => 100, 'errorRate' => 0.5],
                ['hour' => '2024-01-01 11:00', 'avgLatencyMs' => 800, 'requestCount' => 50, 'errorRate' => 2.0],
                ['hour' => '2024-01-01 12:00', 'avgLatencyMs' => 1500, 'requestCount' => 20, 'errorRate' => 10.0],
            ]);

        $result = $this->service->getHealthHistory($user, 'api.example.com', new \DateTimeImmutable('-24 hours'));

        self::assertCount(3, $result);
        self::assertSame('green', $result[0]['status']);
        self::assertSame('yellow', $result[1]['status']);
        self::assertSame('red', $result[2]['status']);
    }

    #[Test]
    #[DataProvider('healthStatusProvider')]
    public function calculateHealthStatusReturnsCorrectStatus(
        float $errorRate,
        int $avgLatencyMs,
        int $criticalDrifts,
        string $expectedStatus
    ): void {
        $result = $this->service->calculateHealthStatus($errorRate, $avgLatencyMs, $criticalDrifts);

        self::assertSame($expectedStatus, $result);
    }

    /**
     * @return iterable<string, array{float, int, int, string}>
     */
    public static function healthStatusProvider(): iterable
    {
        yield 'green - low error rate and latency' => [0.5, 100, 0, 'green'];
        yield 'green - at threshold' => [1.0, 500, 0, 'green'];
        yield 'yellow - high error rate' => [2.0, 100, 0, 'yellow'];
        yield 'yellow - high latency' => [0.5, 800, 0, 'yellow'];
        yield 'yellow - both elevated' => [3.0, 700, 0, 'yellow'];
        yield 'red - very high error rate' => [10.0, 100, 0, 'red'];
        yield 'red - very high latency' => [0.5, 1500, 0, 'red'];
        yield 'red - critical drifts' => [0.5, 100, 1, 'red'];
        yield 'red - all bad' => [10.0, 1500, 5, 'red'];
    }

    #[Test]
    public function calculateHealthStatusUsesCustomThresholds(): void
    {
        $thresholds = [
            'errorRateYellow' => 0.5,
            'errorRateRed' => 2.0,
            'latencyYellow' => 200,
            'latencyRed' => 500,
        ];

        self::assertSame('green', $this->service->calculateHealthStatus(0.3, 100, 0, $thresholds));
        self::assertSame('yellow', $this->service->calculateHealthStatus(1.0, 100, 0, $thresholds));
        self::assertSame('red', $this->service->calculateHealthStatus(3.0, 100, 0, $thresholds));
        self::assertSame('yellow', $this->service->calculateHealthStatus(0.3, 300, 0, $thresholds));
        self::assertSame('red', $this->service->calculateHealthStatus(0.3, 600, 0, $thresholds));
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
        $token->setTokenHash(hash('sha256', 'test'));

        return $token;
    }

    private function createSchema(ApiToken $token): ApiSchema
    {
        $schema = new ApiSchema();
        $schema->setToken($token);
        $schema->setTargetHost('api.example.com');
        $schema->setEndpointPath('/api/test');
        $schema->setHttpMethod('GET');
        $schema->setSchemaType(\SentinelPHP\Dto\Enum\SchemaType::Response);
        $schema->setJsonSchema(['type' => 'object']);

        return $schema;
    }

    private function createDrift(ApiSchema $schema, ApiToken $token): SchemaDrift
    {
        $drift = new SchemaDrift();
        $drift->setSchema($schema);
        $drift->setToken($token);
        $drift->setDriftType(DriftType::FieldAdded);
        $drift->setPath('$.newField');
        $drift->setSeverity(DriftSeverity::Critical);

        return $drift;
    }
}
