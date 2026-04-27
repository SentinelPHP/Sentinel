<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\DriftPayload;
use App\Entity\RequestLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DriftPayload::class)]
final class DriftPayloadTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $payload = new DriftPayload();

        self::assertInstanceOf(Uuid::class, $payload->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $payload->getCreatedAt());
        self::assertNull($payload->getRequestBody());
        self::assertNull($payload->getResponseBody());
    }

    #[Test]
    public function constructorAcceptsCustomUuid(): void
    {
        $customId = Uuid::v7();
        $payload = new DriftPayload($customId);

        self::assertSame($customId, $payload->getId());
    }

    #[Test]
    public function settersAndGettersWork(): void
    {
        $payload = new DriftPayload();
        $requestLog = new RequestLog();

        $requestBodyContent = '{"name": "John"}';
        $responseBodyContent = '{"status": "ok"}';

        $payload->setRequestLog($requestLog);
        $payload->setRequestBody($requestBodyContent);
        $payload->setResponseBody($responseBodyContent);

        self::assertSame($requestLog, $payload->getRequestLog());
        self::assertSame($requestBodyContent, $payload->getRequestBody());
        self::assertSame($responseBodyContent, $payload->getResponseBody());
    }

    #[Test]
    public function requestBodyCanBeSetToNull(): void
    {
        $payload = new DriftPayload();

        $payload->setRequestBody('{"data": "value"}');
        self::assertSame('{"data": "value"}', $payload->getRequestBody());

        $payload->setRequestBody(null);
        self::assertNull($payload->getRequestBody());
    }

    #[Test]
    public function responseBodyCanBeSetToNull(): void
    {
        $payload = new DriftPayload();

        $payload->setResponseBody('{"result": "success"}');
        self::assertSame('{"result": "success"}', $payload->getResponseBody());

        $payload->setResponseBody(null);
        self::assertNull($payload->getResponseBody());
    }

    #[Test]
    public function fluentSettersReturnSelf(): void
    {
        $payload = new DriftPayload();
        $requestLog = new RequestLog();

        self::assertSame($payload, $payload->setRequestLog($requestLog));
        self::assertSame($payload, $payload->setRequestBody('{}'));
        self::assertSame($payload, $payload->setResponseBody('{}'));
    }

    #[Test]
    public function canStoreLargeBodyContent(): void
    {
        $payload = new DriftPayload();

        $largeContent = str_repeat('{"data": "test"}', 10000);

        $payload->setRequestBody($largeContent);
        $payload->setResponseBody($largeContent);

        self::assertSame($largeContent, $payload->getRequestBody());
        self::assertSame($largeContent, $payload->getResponseBody());
    }
}
