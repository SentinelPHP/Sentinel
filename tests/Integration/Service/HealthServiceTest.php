<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\HealthCheckServiceInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class HealthServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private HealthCheckServiceInterface $healthCheckService;
    private MockHandler $mockHandler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->healthCheckService = self::getContainer()->get(HealthCheckServiceInterface::class);
        $mockHandler = self::getContainer()->get('test.guzzle.mock_handler');
        \assert($mockHandler instanceof MockHandler);
        $this->mockHandler = $mockHandler;
        $this->mockHandler->reset();
    }

    #[Test]
    public function healthCheckServiceReturnsValidStructure(): void
    {
        $this->mockHandler->append(new Response(200));

        $status = $this->healthCheckService->getHealthStatus();

        self::assertArrayHasKey('status', $status);
        self::assertArrayHasKey('timestamp', $status);
        self::assertArrayHasKey('checks', $status);
        self::assertContains($status['status'], ['ok', 'degraded']);
    }

    #[Test]
    public function healthCheckServiceIncludesAllChecks(): void
    {
        $this->mockHandler->append(new Response(200));

        $status = $this->healthCheckService->getHealthStatus();

        self::assertArrayHasKey('database', $status['checks']);
        self::assertArrayHasKey('redis', $status['checks']);
        self::assertArrayHasKey('outbound', $status['checks']);
    }

    #[Test]
    public function allChecksPassReturnsOkStatus(): void
    {
        $this->mockHandler->append(new Response(200));

        $status = $this->healthCheckService->getHealthStatus();

        self::assertSame('ok', $status['status']);
        self::assertSame('ok', $status['checks']['database']['status']);
        self::assertSame('ok', $status['checks']['redis']['status']);
        self::assertSame('ok', $status['checks']['outbound']['status']);
    }

    #[Test]
    public function outboundFailureReturnsDegradedStatus(): void
    {
        $this->mockHandler->append(new Response(500));

        $status = $this->healthCheckService->getHealthStatus();

        self::assertSame('degraded', $status['status']);
        self::assertSame('error', $status['checks']['outbound']['status']);
    }

    #[Test]
    public function databaseCheckReturnsOkStatus(): void
    {
        $result = $this->healthCheckService->checkDatabase();

        self::assertSame('ok', $result['status']);
        self::assertArrayHasKey('latency_ms', $result);
    }

    #[Test]
    public function redisCheckReturnsOkStatus(): void
    {
        $result = $this->healthCheckService->checkRedis();

        self::assertSame('ok', $result['status']);
        self::assertArrayHasKey('latency_ms', $result);
    }

    #[Test]
    public function outboundCheckReturnsOkForSuccessfulResponse(): void
    {
        $this->mockHandler->append(new Response(200));

        $result = $this->healthCheckService->checkOutbound();

        self::assertSame('ok', $result['status']);
        self::assertArrayHasKey('latency_ms', $result);
        self::assertArrayHasKey('url', $result);
    }

    #[Test]
    public function outboundCheckReturnsErrorForFailedResponse(): void
    {
        $this->mockHandler->append(new Response(503));

        $result = $this->healthCheckService->checkOutbound();

        self::assertSame('error', $result['status']);
        self::assertArrayHasKey('message', $result);
        $message = $result['message'] ?? '';
        self::assertStringContainsString('503', $message);
    }
}
