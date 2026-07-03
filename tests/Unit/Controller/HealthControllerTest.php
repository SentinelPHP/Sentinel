<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\HealthController;
use App\Security\IpAccessCheckerInterface;
use App\Service\HealthCheckServiceInterface;
use App\Service\StatusServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(HealthController::class)]
#[AllowMockObjectsWithoutExpectations]
final class HealthControllerTest extends TestCase
{
    private HealthCheckServiceInterface&MockObject $healthCheckService;
    private StatusServiceInterface&MockObject $statusService;
    private IpAccessCheckerInterface&MockObject $ipAccessChecker;
    private HealthController $controller;

    protected function setUp(): void
    {
        $this->healthCheckService = $this->createMock(HealthCheckServiceInterface::class);
        $this->statusService = $this->createMock(StatusServiceInterface::class);
        $this->ipAccessChecker = $this->createMock(IpAccessCheckerInterface::class);

        $this->controller = new HealthController(
            $this->healthCheckService,
            $this->statusService,
            $this->ipAccessChecker,
        );
    }

    #[Test]
    public function healthReturns200WhenAllChecksPass(): void
    {
        $this->healthCheckService
            ->expects(self::once())
            ->method('getHealthStatus')
            ->willReturn([
                'status' => 'ok',
                'timestamp' => '2026-04-17T20:00:00+02:00',
                'checks' => [
                    'database' => ['status' => 'ok', 'latency_ms' => 5],
                    'redis' => ['status' => 'ok', 'latency_ms' => 2],
                    'outbound' => ['status' => 'ok', 'latency_ms' => 100],
                ],
            ]);

        $response = $this->controller->health();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $data = $this->decodeJsonResponse($response);
        self::assertSame('ok', $data['status']);
        self::assertArrayHasKey('checks', $data);
    }

    #[Test]
    public function healthReturns503WhenStatusIsDegraded(): void
    {
        $this->healthCheckService
            ->expects(self::once())
            ->method('getHealthStatus')
            ->willReturn([
                'status' => 'degraded',
                'timestamp' => '2026-04-17T20:00:00+02:00',
                'checks' => [
                    'database' => ['status' => 'error', 'message' => 'Connection refused'],
                    'redis' => ['status' => 'ok', 'latency_ms' => 2],
                    'outbound' => ['status' => 'ok', 'latency_ms' => 100],
                ],
            ]);

        $response = $this->controller->health();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());

        $data = $this->decodeJsonResponse($response);
        self::assertSame('degraded', $data['status']);
        /** @var array<string, array<string, mixed>> $checks */
        $checks = $data['checks'];
        /** @var array<string, mixed> $database */
        $database = $checks['database'];
        self::assertSame('error', $database['status']);
    }

    #[Test]
    public function healthResponseIncludesAllCheckDetails(): void
    {
        $healthData = [
            'status' => 'ok',
            'timestamp' => '2026-04-17T20:00:00+02:00',
            'checks' => [
                'database' => ['status' => 'ok', 'latency_ms' => 5],
                'redis' => ['status' => 'ok', 'latency_ms' => 2],
                'outbound' => ['status' => 'ok', 'latency_ms' => 100, 'url' => 'https://httpbin.org/status/200'],
            ],
        ];

        $this->healthCheckService
            ->method('getHealthStatus')
            ->willReturn($healthData);

        $response = $this->controller->health();
        $data = $this->decodeJsonResponse($response);

        self::assertSame($healthData, $data);
    }

    #[Test]
    public function statusReturns200WhenIpIsAllowed(): void
    {
        $request = Request::create('/status', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $this->ipAccessChecker
            ->expects(self::once())
            ->method('isAllowed')
            ->with($request)
            ->willReturn(true);

        $statusData = [
            'uptime_seconds' => 3600,
            'uptime_human' => '1h 0m 0s',
            'total_requests_proxied' => 1000,
            'active_connections' => 5,
            'timestamp' => '2026-04-17T20:00:00+02:00',
        ];

        $this->statusService
            ->expects(self::once())
            ->method('getStatus')
            ->willReturn($statusData);

        $response = $this->controller->status($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeJsonResponse($response);
        self::assertSame($statusData, $data);
    }

    #[Test]
    public function statusReturns403WhenIpIsNotAllowed(): void
    {
        $request = Request::create('/status', 'GET', [], [], [], ['REMOTE_ADDR' => '8.8.8.8']);

        $this->ipAccessChecker
            ->expects(self::once())
            ->method('isAllowed')
            ->with($request)
            ->willReturn(false);

        $this->statusService
            ->expects(self::never())
            ->method('getStatus');

        $response = $this->controller->status($request);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $data = $this->decodeJsonResponse($response);
        self::assertTrue($data['error']);
        self::assertSame('Access denied', $data['message']);
    }

    #[Test]
    public function statusDoesNotCallStatusServiceWhenAccessDenied(): void
    {
        $request = Request::create('/status', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.1']);

        $this->ipAccessChecker
            ->method('isAllowed')
            ->willReturn(false);

        $this->statusService
            ->expects(self::never())
            ->method('getStatus');

        $this->controller->status($request);
    }

    #[Test]
    public function statusResponseIncludesAllMetrics(): void
    {
        $request = Request::create('/status', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);

        $this->ipAccessChecker
            ->method('isAllowed')
            ->willReturn(true);

        $this->statusService
            ->method('getStatus')
            ->willReturn([
                'uptime_seconds' => 86400,
                'uptime_human' => '1d 0h 0m 0s',
                'total_requests_proxied' => 50000,
                'active_connections' => 25,
                'timestamp' => '2026-04-17T20:00:00+02:00',
            ]);

        $response = $this->controller->status($request);
        $data = $this->decodeJsonResponse($response);

        self::assertArrayHasKey('uptime_seconds', $data);
        self::assertArrayHasKey('uptime_human', $data);
        self::assertArrayHasKey('total_requests_proxied', $data);
        self::assertArrayHasKey('active_connections', $data);
        self::assertArrayHasKey('timestamp', $data);
    }

    #[Test]
    public function healthReturnsJsonContentType(): void
    {
        $this->healthCheckService
            ->method('getHealthStatus')
            ->willReturn([
                'status' => 'ok',
                'timestamp' => '2026-04-17T20:00:00+02:00',
                'checks' => [],
            ]);

        $response = $this->controller->health();

        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function statusReturnsJsonContentType(): void
    {
        $request = Request::create('/status', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $this->ipAccessChecker
            ->method('isAllowed')
            ->willReturn(true);

        $this->statusService
            ->method('getStatus')
            ->willReturn([
                'uptime_seconds' => 0,
                'uptime_human' => '0s',
                'total_requests_proxied' => 0,
                'active_connections' => 0,
                'timestamp' => '2026-04-17T20:00:00+02:00',
            ]);

        $response = $this->controller->status($request);

        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function statusForbiddenResponseReturnsJsonContentType(): void
    {
        $request = Request::create('/status', 'GET', [], [], [], ['REMOTE_ADDR' => '8.8.8.8']);

        $this->ipAccessChecker
            ->method('isAllowed')
            ->willReturn(false);

        $response = $this->controller->status($request);

        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);

        $data = json_decode($content, true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
