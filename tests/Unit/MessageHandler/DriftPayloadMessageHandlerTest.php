<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\DriftPayload;
use App\Entity\RequestLog;
use App\Message\DriftPayloadMessage;
use App\MessageHandler\DriftPayloadMessageHandler;
use App\Service\BodyCompressionServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DriftPayloadMessageHandler::class)]
final class DriftPayloadMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private BodyCompressionServiceInterface&MockObject $compressionService;
    private DriftPayloadMessageHandler $handler;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->compressionService = $this->createMock(BodyCompressionServiceInterface::class);
        $this->handler = new DriftPayloadMessageHandler($this->entityManager, $this->compressionService);
    }

    #[Test]
    public function invokePersistsDriftPayloadWhenRequestLogExists(): void
    {
        $requestLogId = Uuid::v7();
        $requestLog = new RequestLog($requestLogId);

        $this->entityManager
            ->expects(self::once())
            ->method('find')
            ->with(RequestLog::class, self::callback(fn (Uuid $uuid) => $uuid->toRfc4122() === $requestLogId->toRfc4122()))
            ->willReturn($requestLog);

        $this->compressionService
            ->expects(self::exactly(2))
            ->method('compress')
            ->willReturnCallback(fn (string $data) => 'gzip:' . base64_encode($data));

        $message = new DriftPayloadMessage(
            requestLogId: $requestLogId->toRfc4122(),
            requestBody: '{"name":"test"}',
            responseBody: '{"id":1}',
        );

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (DriftPayload $payload) use ($requestLog): bool {
                return $payload->getRequestLog() === $requestLog
                    && str_starts_with($payload->getRequestBody() ?? '', 'gzip:')
                    && str_starts_with($payload->getResponseBody() ?? '', 'gzip:')
                    && $payload->isCompressed() === true;
            }));

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        ($this->handler)($message);
    }

    #[Test]
    public function invokeDoesNothingWhenRequestLogNotFound(): void
    {
        $requestLogId = Uuid::v7();

        $this->entityManager
            ->expects(self::once())
            ->method('find')
            ->with(RequestLog::class, self::callback(fn (Uuid $uuid) => $uuid->toRfc4122() === $requestLogId->toRfc4122()))
            ->willReturn(null);

        $message = new DriftPayloadMessage(
            requestLogId: $requestLogId->toRfc4122(),
            requestBody: '{"name":"test"}',
            responseBody: '{"id":1}',
        );

        $this->compressionService
            ->expects(self::never())
            ->method('compress');

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        ($this->handler)($message);
    }

    #[Test]
    public function invokePersistsWithNullBodies(): void
    {
        $requestLogId = Uuid::v7();
        $requestLog = new RequestLog($requestLogId);

        $this->entityManager
            ->expects(self::once())
            ->method('find')
            ->willReturn($requestLog);

        $this->compressionService
            ->expects(self::never())
            ->method('compress');

        $message = new DriftPayloadMessage(
            requestLogId: $requestLogId->toRfc4122(),
            requestBody: null,
            responseBody: null,
        );

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (DriftPayload $payload): bool {
                return $payload->getRequestBody() === null
                    && $payload->getResponseBody() === null
                    && $payload->isCompressed() === true;
            }));

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        ($this->handler)($message);
    }

    #[Test]
    public function invokePersistsWithEmptyStringBodies(): void
    {
        $requestLogId = Uuid::v7();
        $requestLog = new RequestLog($requestLogId);

        $this->entityManager
            ->expects(self::once())
            ->method('find')
            ->willReturn($requestLog);

        $this->compressionService
            ->expects(self::never())
            ->method('compress');

        $message = new DriftPayloadMessage(
            requestLogId: $requestLogId->toRfc4122(),
            requestBody: '',
            responseBody: '',
        );

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (DriftPayload $payload): bool {
                return $payload->getRequestBody() === ''
                    && $payload->getResponseBody() === ''
                    && $payload->isCompressed() === true;
            }));

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        ($this->handler)($message);
    }
}
