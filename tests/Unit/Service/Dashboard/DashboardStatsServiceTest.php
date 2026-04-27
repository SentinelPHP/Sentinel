<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dashboard;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\SchemaDrift;
use App\Entity\User;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use App\Repository\ApiTokenRepositoryInterface;
use App\Repository\RequestLogRepositoryInterface;
use App\Repository\SchemaDriftRepositoryInterface;
use App\Service\AccessControl\AccessControlServiceInterface;
use App\Service\Dashboard\DashboardStatsService;
use App\Service\HealthCheckServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DashboardStatsService::class)]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class DashboardStatsServiceTest extends TestCase
{
    private AccessControlServiceInterface&MockObject $accessControlService;
    private ApiTokenRepositoryInterface&MockObject $apiTokenRepository;
    private RequestLogRepositoryInterface&MockObject $requestLogRepository;
    private SchemaDriftRepositoryInterface&MockObject $schemaDriftRepository;
    private HealthCheckServiceInterface&MockObject $healthCheckService;
    private DashboardStatsService $service;

    protected function setUp(): void
    {
        $this->accessControlService = $this->createMock(AccessControlServiceInterface::class);
        $this->apiTokenRepository = $this->createMock(ApiTokenRepositoryInterface::class);
        $this->requestLogRepository = $this->createMock(RequestLogRepositoryInterface::class);
        $this->schemaDriftRepository = $this->createMock(SchemaDriftRepositoryInterface::class);
        $this->healthCheckService = $this->createMock(HealthCheckServiceInterface::class);

        $this->service = new DashboardStatsService(
            $this->accessControlService,
            $this->apiTokenRepository,
            $this->requestLogRepository,
            $this->schemaDriftRepository,
            $this->healthCheckService,
        );
    }

    #[Test]
    public function getTokenStatsReturnsCorrectCounts(): void
    {
        $user = $this->createUser();
        $token1 = $this->createToken();
        $token2 = $this->createToken();

        $this->accessControlService
            ->expects(self::once())
            ->method('getAccessibleTokens')
            ->with($user)
            ->willReturn([$token1, $token2]);

        $this->apiTokenRepository
            ->expects(self::once())
            ->method('countByActiveStatus')
            ->willReturn(['total' => 2, 'active' => 1]);

        $result = $this->service->getTokenStats($user);

        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['active']);
        self::assertSame(1, $result['inactive']);
    }

    #[Test]
    public function getTokenStatsReturnsZerosWhenNoTokens(): void
    {
        $user = $this->createUser();

        $this->accessControlService
            ->expects(self::once())
            ->method('getAccessibleTokens')
            ->with($user)
            ->willReturn([]);

        $this->apiTokenRepository
            ->expects(self::never())
            ->method('countByActiveStatus');

        $result = $this->service->getTokenStats($user);

        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['active']);
        self::assertSame(0, $result['inactive']);
    }

    #[Test]
    public function getRequestStatsReturnsCountAndTrend(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();
        $since = new \DateTimeImmutable('-24 hours');

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->requestLogRepository
            ->expects(self::once())
            ->method('countSince')
            ->willReturn(150);

        $this->requestLogRepository
            ->expects(self::once())
            ->method('getHourlyTrend')
            ->willReturn(['2024-01-01 10:00' => 50, '2024-01-01 11:00' => 100]);

        $result = $this->service->getRequestStats($user, $since);

        self::assertSame(150, $result['last24h']);
        self::assertCount(2, $result['trend']);
    }

    #[Test]
    public function getRequestStatsReturnsEmptyWhenNoTokens(): void
    {
        $user = $this->createUser();
        $since = new \DateTimeImmutable('-24 hours');

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([]);

        $result = $this->service->getRequestStats($user, $since);

        self::assertSame(0, $result['last24h']);
        self::assertEmpty($result['trend']);
    }

    #[Test]
    public function getDriftStatsReturnsSeverityCounts(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();
        $since = new \DateTimeImmutable('-24 hours');

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->schemaDriftRepository
            ->expects(self::once())
            ->method('countBySeveritySince')
            ->willReturn(['critical' => 2, 'warning' => 5, 'info' => 10]);

        $result = $this->service->getDriftStats($user, $since);

        self::assertSame(17, $result['total']);
        self::assertSame(2, $result['critical']);
        self::assertSame(5, $result['warning']);
        self::assertSame(10, $result['info']);
    }

    #[Test]
    public function getDriftStatsReturnsZerosWhenNoTokens(): void
    {
        $user = $this->createUser();
        $since = new \DateTimeImmutable('-24 hours');

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([]);

        $result = $this->service->getDriftStats($user, $since);

        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['critical']);
        self::assertSame(0, $result['warning']);
        self::assertSame(0, $result['info']);
    }

    #[Test]
    public function getRecentDriftsReturnsMappedDrifts(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();
        $schema = $this->createSchema($token);
        $drift = $this->createDrift($schema, $token);

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->schemaDriftRepository
            ->expects(self::once())
            ->method('findRecentByTokenIds')
            ->willReturn([$drift]);

        $result = $this->service->getRecentDrifts($user, 5);

        self::assertCount(1, $result);
        self::assertSame($drift->getId()->toRfc4122(), $result[0]['id']);
        self::assertSame('critical', $result[0]['severity']);
        self::assertSame('/api/test', $result[0]['endpoint']);
    }

    #[Test]
    public function getRecentDriftsReturnsEmptyWhenNoTokens(): void
    {
        $user = $this->createUser();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([]);

        $result = $this->service->getRecentDrifts($user);

        self::assertEmpty($result);
    }

    #[Test]
    public function getServiceHealthSummaryCalculatesHealthStatus(): void
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

        $result = $this->service->getServiceHealthSummary($user);

        self::assertCount(3, $result);
        
        self::assertSame('api.example.com', $result[0]['host']);
        self::assertSame('green', $result[0]['status']);
        
        self::assertSame('slow.example.com', $result[1]['host']);
        self::assertSame('yellow', $result[1]['status']);
        
        self::assertSame('bad.example.com', $result[2]['host']);
        self::assertSame('red', $result[2]['status']);
    }

    #[Test]
    public function getServiceHealthSummaryReturnsEmptyWhenNoTokens(): void
    {
        $user = $this->createUser();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([]);

        $result = $this->service->getServiceHealthSummary($user);

        self::assertEmpty($result);
    }

    #[Test]
    public function getOverviewStatsReturnsCompleteStats(): void
    {
        $user = $this->createUser();
        $token = $this->createToken();

        $this->accessControlService
            ->method('getAccessibleTokens')
            ->willReturn([$token]);

        $this->apiTokenRepository
            ->method('countByActiveStatus')
            ->willReturn(['total' => 1, 'active' => 1]);

        $this->requestLogRepository
            ->method('countSince')
            ->willReturn(100);

        $this->requestLogRepository
            ->method('getHourlyTrend')
            ->willReturn([]);

        $this->requestLogRepository
            ->method('getHostStats')
            ->willReturn([]);

        $this->schemaDriftRepository
            ->method('countBySeveritySince')
            ->willReturn([]);

        $this->schemaDriftRepository
            ->method('findRecentByTokenIds')
            ->willReturn([]);

        $this->healthCheckService
            ->expects(self::once())
            ->method('getHealthStatus')
            ->willReturn([
                'status' => 'ok',
                'timestamp' => '2024-01-01T00:00:00+00:00',
                'checks' => [],
            ]);

        $result = $this->service->getOverviewStats($user);

        self::assertArrayHasKey('tokens', $result);
        self::assertArrayHasKey('requests', $result);
        self::assertArrayHasKey('drifts', $result);
        self::assertArrayHasKey('health', $result);
        self::assertArrayHasKey('recentDrifts', $result);
        self::assertArrayHasKey('services', $result);
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
