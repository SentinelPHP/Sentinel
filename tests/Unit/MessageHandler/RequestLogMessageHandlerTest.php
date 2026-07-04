<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\ApiToken;
use App\Entity\RequestLog;
use App\Enum\DataProtectionStrategy;
use App\Enum\LogLevel;
use App\Message\RequestLogMessage;
use App\MessageHandler\RequestLogMessageHandler;
use App\Repository\ApiTokenRepository;
use App\Repository\RequestLogRepository;
use App\Service\BodyCompressionServiceInterface;
use App\Service\Dashboard\LatencyMetricsServiceInterface;
use App\Service\DataProtection\DataProtectionServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RequestLogMessageHandler::class)]
#[AllowMockObjectsWithoutExpectations]
final class RequestLogMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private ApiTokenRepository&MockObject $tokenRepository;
    private RequestLogRepository&MockObject $requestLogRepository;
    private DataProtectionServiceInterface&MockObject $dataProtectionService;
    private BodyCompressionServiceInterface&MockObject $compressionService;
    private LatencyMetricsServiceInterface&MockObject $latencyMetricsService;
    private RequestLogMessageHandler $handler;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->tokenRepository = $this->createMock(ApiTokenRepository::class);
        $this->requestLogRepository = $this->createMock(RequestLogRepository::class);
        $this->dataProtectionService = $this->createMock(DataProtectionServiceInterface::class);

        $this->dataProtectionService
            ->method('getEffectiveStrategy')
            ->willReturn(DataProtectionStrategy::None);

        $this->compressionService = $this->createMock(BodyCompressionServiceInterface::class);
        $this->latencyMetricsService = $this->createMock(LatencyMetricsServiceInterface::class);

        // By default, requestLogRepository returns null (log doesn't exist yet)
        $this->requestLogRepository
            ->method('find')
            ->willReturn(null);

        $this->handler = new RequestLogMessageHandler(
            $this->entityManager,
            $this->tokenRepository,
            $this->requestLogRepository,
            $this->dataProtectionService,
            $this->compressionService,
            $this->latencyMetricsService,
        );
    }

    #[Test]
    public function invokePersistsRequestLogWithToken(): void
    {
        $requestLogId = Uuid::v7();
        $tokenId = Uuid::v7();
        $token = $this->createMock(ApiToken::class);

        $this->tokenRepository
            ->expects(self::once())
            ->method('find')
            ->with(self::callback(fn (Uuid $uuid) => $uuid->toRfc4122() === $tokenId->toRfc4122()))
            ->willReturn($token);

        $message = new RequestLogMessage(
            requestLogId: $requestLogId->toRfc4122(),
            tokenId: $tokenId->toRfc4122(),
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

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (RequestLog $log) use ($token, $requestLogId): bool {
                return $log->getId()->toRfc4122() === $requestLogId->toRfc4122()
                    && $log->getToken() === $token
                    && $log->getTargetHost() === 'api.example.com'
                    && $log->getRequestMethod() === 'POST'
                    && $log->getRequestPath() === '/users'
                    && $log->getResponseStatusCode() === 201
                    && $log->getLatencyMs() === 150
                    && $log->getRequestHeaders() === '{"Content-Type":"application/json"}'
                    && $log->getRequestBody() === '{"name":"test"}'
                    && $log->getResponseHeaders() === '{"X-Request-Id":"abc123"}'
                    && $log->getResponseBody() === '{"id":1}';
            }));

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        ($this->handler)($message);
    }

    #[Test]
    public function invokePersistsRequestLogWithNullToken(): void
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

        $this->tokenRepository
            ->expects(self::never())
            ->method('find');

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (RequestLog $log): bool {
                return $log->getToken() === null
                    && $log->getTargetHost() === 'api.example.com'
                    && $log->getRequestMethod() === 'GET'
                    && $log->getRequestPath() === '/health'
                    && $log->getResponseStatusCode() === 200
                    && $log->getLatencyMs() === 10;
            }));

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        ($this->handler)($message);
    }

    #[Test]
    public function invokeSkipsLoggingWhenLogLevelIsNone(): void
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

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        ($this->handler)($message);
    }

    #[Test]
    public function invokeFiltersFieldsBasedOnLogLevel(): void
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

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (RequestLog $log): bool {
                return $log->getRequestHeaders() === '{"Content-Type":"application/json"}'
                    && $log->getRequestBody() === null
                    && $log->getResponseHeaders() === '{"X-Request-Id":"abc123"}'
                    && $log->getResponseBody() === null;
            }));

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        ($this->handler)($message);
    }

    #[Test]
    public function invokeLogsAllFieldsForFullAudit(): void
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

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (RequestLog $log): bool {
                return $log->getRequestHeaders() === '{"Content-Type":"application/json"}'
                    && $log->getRequestBody() === '{"name":"test"}'
                    && $log->getResponseHeaders() === '{"X-Request-Id":"abc123"}'
                    && $log->getResponseBody() === '{"id":1}';
            }));

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        ($this->handler)($message);
    }

    #[Test]
    public function invokeAlwaysRedactsSensitiveHeadersWhenDataProtectionStrategyIsNone(): void
    {
        $this->dataProtectionService
            ->expects(self::never())
            ->method('protect');

        $message = new RequestLogMessage(
            requestLogId: Uuid::v7()->toRfc4122(),
            tokenId: null,
            targetHost: 'api.example.com',
            requestMethod: 'POST',
            requestPath: '/users',
            responseStatusCode: 201,
            latencyMs: 100,
            logLevel: LogLevel::FullAudit,
            requestHeaders: '{"Authorization":"Bearer token-123","Cookie":"session=abc","Content-Type":"application/json"}',
            requestBody: '{"name":"test"}',
            responseHeaders: '{"Proxy-Authorization":"Bearer upstream-secret","Set-Cookie":"sessionid=xyz; Path=/","X-Request-Id":"abc123"}',
            responseBody: '{"id":1}',
        );

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (RequestLog $log): bool {
                return $log->getRequestHeaders() === '{"Authorization":"[REDACTED]","Cookie":"[REDACTED]","Content-Type":"application/json"}'
                    && $log->getResponseHeaders() === '{"Proxy-Authorization":"[REDACTED]","Set-Cookie":"[REDACTED]","X-Request-Id":"abc123"}';
            }));

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        ($this->handler)($message);
    }
}
