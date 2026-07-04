<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\RequestLog;
use App\Enum\LogLevel;
use App\Message\RequestLogMessage;
use App\MessageHandler\RequestLogMessageHandler;
use App\Repository\RequestLogRepository;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RequestLogMessageHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private RequestLogMessageHandler $handler;
    private RequestLogRepository $requestLogRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->handler = self::getContainer()->get(RequestLogMessageHandler::class);
        $this->requestLogRepository = self::getContainer()->get(RequestLogRepository::class);
    }

    #[Test]
    public function handlerPersistsRequestLogWithToken(): void
    {
        $token = ApiTokenFactory::createOne();
        $requestLogId = Uuid::v7();

        $message = new RequestLogMessage(
            requestLogId: $requestLogId->toRfc4122(),
            tokenId: $token->getId()->toRfc4122(),
            targetHost: 'api.example.com',
            requestMethod: 'POST',
            requestPath: '/users',
            responseStatusCode: 201,
            latencyMs: 150,
            logLevel: LogLevel::FullAudit,
            requestHeaders: '{"Content-Type":"application/json"}',
            requestBody: '{"name":"test"}',
            responseHeaders: '{"X-Request-Id":"abc123"}',
            responseBody: '{"id":1}',
        );

        ($this->handler)($message);

        $logs = $this->requestLogRepository->findAll();
        self::assertCount(1, $logs);

        $log = $logs[0];
        self::assertSame($requestLogId->toRfc4122(), $log->getId()->toRfc4122());
        self::assertSame($token->getId()->toRfc4122(), $log->getToken()?->getId()->toRfc4122());
        self::assertSame('api.example.com', $log->getTargetHost());
        self::assertSame('POST', $log->getRequestMethod());
        self::assertSame('/users', $log->getRequestPath());
        self::assertSame(201, $log->getResponseStatusCode());
        self::assertSame(150, $log->getLatencyMs());
        self::assertSame('{"Content-Type":"application/json"}', $log->getRequestHeaders());
        self::assertSame('{"name":"test"}', $log->getRequestBody());
        self::assertSame('{"X-Request-Id":"abc123"}', $log->getResponseHeaders());
        self::assertSame('{"id":1}', $log->getResponseBody());
    }

    #[Test]
    public function handlerPersistsRequestLogWithoutToken(): void
    {
        $message = new RequestLogMessage(
            requestLogId: Uuid::v7()->toRfc4122(),
            tokenId: null,
            targetHost: 'api.example.com',
            requestMethod: 'GET',
            requestPath: '/health',
            responseStatusCode: 200,
            latencyMs: 10,
            logLevel: LogLevel::MetadataOnly,
        );

        ($this->handler)($message);

        $logs = $this->requestLogRepository->findAll();
        self::assertCount(1, $logs);

        $log = $logs[0];
        self::assertNull($log->getToken());
        self::assertSame('api.example.com', $log->getTargetHost());
        self::assertSame('GET', $log->getRequestMethod());
        self::assertSame('/health', $log->getRequestPath());
        self::assertSame(200, $log->getResponseStatusCode());
        self::assertSame(10, $log->getLatencyMs());
    }

    #[Test]
    public function handlerSkipsLoggingWhenLogLevelIsNone(): void
    {
        $message = new RequestLogMessage(
            requestLogId: Uuid::v7()->toRfc4122(),
            tokenId: null,
            targetHost: 'api.example.com',
            requestMethod: 'GET',
            requestPath: '/health',
            responseStatusCode: 200,
            latencyMs: 10,
            logLevel: LogLevel::None,
        );

        ($this->handler)($message);

        $logs = $this->requestLogRepository->findAll();
        self::assertCount(0, $logs);
    }

    #[Test]
    public function handlerFiltersFieldsBasedOnLogLevelHeaders(): void
    {
        $message = new RequestLogMessage(
            requestLogId: Uuid::v7()->toRfc4122(),
            tokenId: null,
            targetHost: 'api.example.com',
            requestMethod: 'POST',
            requestPath: '/users',
            responseStatusCode: 201,
            latencyMs: 100,
            logLevel: LogLevel::Headers,
            requestHeaders: '{"Content-Type":"application/json"}',
            requestBody: '{"name":"test"}',
            responseHeaders: '{"X-Request-Id":"abc123"}',
            responseBody: '{"id":1}',
        );

        ($this->handler)($message);

        $logs = $this->requestLogRepository->findAll();
        self::assertCount(1, $logs);

        $log = $logs[0];
        self::assertSame('{"Content-Type":"application/json"}', $log->getRequestHeaders());
        self::assertNull($log->getRequestBody());
        self::assertSame('{"X-Request-Id":"abc123"}', $log->getResponseHeaders());
        self::assertNull($log->getResponseBody());
    }

    #[Test]
    public function handlerFiltersFieldsBasedOnLogLevelMetadataOnly(): void
    {
        $message = new RequestLogMessage(
            requestLogId: Uuid::v7()->toRfc4122(),
            tokenId: null,
            targetHost: 'api.example.com',
            requestMethod: 'POST',
            requestPath: '/users',
            responseStatusCode: 201,
            latencyMs: 100,
            logLevel: LogLevel::MetadataOnly,
            requestHeaders: '{"Content-Type":"application/json"}',
            requestBody: '{"name":"test"}',
            responseHeaders: '{"X-Request-Id":"abc123"}',
            responseBody: '{"id":1}',
        );

        ($this->handler)($message);

        $logs = $this->requestLogRepository->findAll();
        self::assertCount(1, $logs);

        $log = $logs[0];
        self::assertNull($log->getRequestHeaders());
        self::assertNull($log->getRequestBody());
        self::assertNull($log->getResponseHeaders());
        self::assertNull($log->getResponseBody());
    }

    #[Test]
    public function handlerLogsAllFieldsForFullAudit(): void
    {
        $message = new RequestLogMessage(
            requestLogId: Uuid::v7()->toRfc4122(),
            tokenId: null,
            targetHost: 'api.example.com',
            requestMethod: 'POST',
            requestPath: '/users',
            responseStatusCode: 201,
            latencyMs: 100,
            logLevel: LogLevel::FullAudit,
            requestHeaders: '{"Content-Type":"application/json"}',
            requestBody: '{"name":"test"}',
            responseHeaders: '{"X-Request-Id":"abc123"}',
            responseBody: '{"id":1}',
        );

        ($this->handler)($message);

        $logs = $this->requestLogRepository->findAll();
        self::assertCount(1, $logs);

        $log = $logs[0];
        self::assertSame('{"Content-Type":"application/json"}', $log->getRequestHeaders());
        self::assertSame('{"name":"test"}', $log->getRequestBody());
        self::assertSame('{"X-Request-Id":"abc123"}', $log->getResponseHeaders());
        self::assertSame('{"id":1}', $log->getResponseBody());
    }

    #[Test]
    public function handlerPersistsMultipleLogsIndependently(): void
    {
        $message1 = new RequestLogMessage(
            requestLogId: Uuid::v7()->toRfc4122(),
            tokenId: null,
            targetHost: 'api1.example.com',
            requestMethod: 'GET',
            requestPath: '/users',
            responseStatusCode: 200,
            latencyMs: 50,
            logLevel: LogLevel::MetadataOnly,
        );

        $message2 = new RequestLogMessage(
            requestLogId: Uuid::v7()->toRfc4122(),
            tokenId: null,
            targetHost: 'api2.example.com',
            requestMethod: 'POST',
            requestPath: '/orders',
            responseStatusCode: 201,
            latencyMs: 100,
            logLevel: LogLevel::MetadataOnly,
        );

        ($this->handler)($message1);
        ($this->handler)($message2);

        $logs = $this->requestLogRepository->findAll();
        self::assertCount(2, $logs);

        $hosts = array_map(fn (RequestLog $log) => $log->getTargetHost(), $logs);
        self::assertContains('api1.example.com', $hosts);
        self::assertContains('api2.example.com', $hosts);
    }

    #[Test]
    public function handlerAlwaysRedactsSensitiveHeadersWhenPersistingLogs(): void
    {
        $message = new RequestLogMessage(
            requestLogId: Uuid::v7()->toRfc4122(),
            tokenId: null,
            targetHost: 'api.example.com',
            requestMethod: 'POST',
            requestPath: '/users',
            responseStatusCode: 201,
            latencyMs: 100,
            logLevel: LogLevel::Headers,
            requestHeaders: '{"Authorization":"Bearer token-123","Cookie":"session=abc","Accept":"application/json"}',
            requestBody: '{"name":"test"}',
            responseHeaders: '{"Proxy-Authorization":"Bearer upstream-secret","Set-Cookie":"sessionid=xyz; Path=/","X-Request-Id":"abc123"}',
            responseBody: '{"id":1}',
        );

        ($this->handler)($message);

        $logs = $this->requestLogRepository->findAll();
        self::assertCount(1, $logs);

        $log = $logs[0];
        self::assertSame('{"Authorization":"[REDACTED]","Cookie":"[REDACTED]","Accept":"application/json"}', $log->getRequestHeaders());
        self::assertSame('{"Proxy-Authorization":"[REDACTED]","Set-Cookie":"[REDACTED]","X-Request-Id":"abc123"}', $log->getResponseHeaders());
    }
}
