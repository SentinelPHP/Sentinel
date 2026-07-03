<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ApiToken;
use App\Entity\RequestLog;
use App\Entity\SchemaDrift;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RequestLog::class)]
#[AllowMockObjectsWithoutExpectations]
final class RequestLogTest extends TestCase
{
    #[Test]
    public function constructorGeneratesUuid(): void
    {
        $log = new RequestLog();

        $id = $log->getId();
        self::assertInstanceOf(Uuid::class, $id);
        self::assertNotEmpty($id->toRfc4122());
    }

    #[Test]
    public function constructorSetsCreatedAt(): void
    {
        $before = new \DateTimeImmutable();
        $log = new RequestLog();
        $after = new \DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $log->getCreatedAt());
        self::assertLessThanOrEqual($after, $log->getCreatedAt());
    }

    #[Test]
    public function setAndGetToken(): void
    {
        $log = new RequestLog();
        $token = $this->createMock(ApiToken::class);

        self::assertNull($log->getToken());

        $result = $log->setToken($token);

        self::assertSame($log, $result);
        self::assertSame($token, $log->getToken());
    }

    #[Test]
    public function setAndGetTargetHost(): void
    {
        $log = new RequestLog();

        $result = $log->setTargetHost('api.example.com');

        self::assertSame($log, $result);
        self::assertSame('api.example.com', $log->getTargetHost());
    }

    #[Test]
    public function setAndGetRequestMethod(): void
    {
        $log = new RequestLog();

        $result = $log->setRequestMethod('POST');

        self::assertSame($log, $result);
        self::assertSame('POST', $log->getRequestMethod());
    }

    #[Test]
    public function setAndGetRequestPath(): void
    {
        $log = new RequestLog();

        $result = $log->setRequestPath('/api/v1/users?page=1');

        self::assertSame($log, $result);
        self::assertSame('/api/v1/users?page=1', $log->getRequestPath());
    }

    #[Test]
    public function setAndGetResponseStatusCode(): void
    {
        $log = new RequestLog();

        $result = $log->setResponseStatusCode(201);

        self::assertSame($log, $result);
        self::assertSame(201, $log->getResponseStatusCode());
    }

    #[Test]
    public function setAndGetLatencyMs(): void
    {
        $log = new RequestLog();

        $result = $log->setLatencyMs(150);

        self::assertSame($log, $result);
        self::assertSame(150, $log->getLatencyMs());
    }

    #[Test]
    public function setAndGetRequestHeaders(): void
    {
        $log = new RequestLog();
        $headers = '{"Content-Type": "application/json"}';

        self::assertNull($log->getRequestHeaders());

        $result = $log->setRequestHeaders($headers);

        self::assertSame($log, $result);
        self::assertSame($headers, $log->getRequestHeaders());
    }

    #[Test]
    public function setAndGetRequestBody(): void
    {
        $log = new RequestLog();
        $body = '{"name": "John"}';

        self::assertNull($log->getRequestBody());

        $result = $log->setRequestBody($body);

        self::assertSame($log, $result);
        self::assertSame($body, $log->getRequestBody());
    }

    #[Test]
    public function setAndGetResponseHeaders(): void
    {
        $log = new RequestLog();
        $headers = '{"X-Request-Id": "abc123"}';

        self::assertNull($log->getResponseHeaders());

        $result = $log->setResponseHeaders($headers);

        self::assertSame($log, $result);
        self::assertSame($headers, $log->getResponseHeaders());
    }

    #[Test]
    public function setAndGetResponseBody(): void
    {
        $log = new RequestLog();
        $body = '{"id": 1, "name": "John"}';

        self::assertNull($log->getResponseBody());

        $result = $log->setResponseBody($body);

        self::assertSame($log, $result);
        self::assertSame($body, $log->getResponseBody());
    }

    #[Test]
    public function tokenCanBeSetToNull(): void
    {
        $log = new RequestLog();
        $token = $this->createMock(ApiToken::class);

        $log->setToken($token);
        self::assertSame($token, $log->getToken());

        $log->setToken(null);
        self::assertNull($log->getToken());
    }

    #[Test]
    public function fluentInterfaceAllowsChaining(): void
    {
        $log = new RequestLog();

        $result = $log
            ->setTargetHost('api.example.com')
            ->setRequestMethod('GET')
            ->setRequestPath('/users')
            ->setResponseStatusCode(200)
            ->setLatencyMs(50)
            ->setRequestHeaders('{}')
            ->setResponseHeaders('{}');

        self::assertSame($log, $result);
        self::assertSame('api.example.com', $log->getTargetHost());
        self::assertSame('GET', $log->getRequestMethod());
        self::assertSame('/users', $log->getRequestPath());
        self::assertSame(200, $log->getResponseStatusCode());
        self::assertSame(50, $log->getLatencyMs());
    }

    #[Test]
    public function constructorAcceptsPreGeneratedUuid(): void
    {
        $uuid = Uuid::v7();
        $log = new RequestLog($uuid);

        self::assertSame($uuid, $log->getId());
    }

    #[Test]
    public function setAndGetSchemaValidated(): void
    {
        $log = new RequestLog();

        self::assertNull($log->isSchemaValidated());

        $result = $log->setSchemaValidated(true);

        self::assertSame($log, $result);
        self::assertTrue($log->isSchemaValidated());

        $log->setSchemaValidated(false);
        self::assertFalse($log->isSchemaValidated());

        $log->setSchemaValidated(null);
        self::assertNull($log->isSchemaValidated());
    }

    #[Test]
    public function setAndGetDriftDetected(): void
    {
        $log = new RequestLog();

        self::assertNull($log->isDriftDetected());

        $result = $log->setDriftDetected(true);

        self::assertSame($log, $result);
        self::assertTrue($log->isDriftDetected());

        $log->setDriftDetected(false);
        self::assertFalse($log->isDriftDetected());

        $log->setDriftDetected(null);
        self::assertNull($log->isDriftDetected());
    }

    #[Test]
    public function setAndGetDrift(): void
    {
        $log = new RequestLog();
        $drift = $this->createMock(SchemaDrift::class);

        self::assertNull($log->getDrift());

        $result = $log->setDrift($drift);

        self::assertSame($log, $result);
        self::assertSame($drift, $log->getDrift());

        $log->setDrift(null);
        self::assertNull($log->getDrift());
    }

    #[Test]
    public function setAndGetIsEncrypted(): void
    {
        $log = new RequestLog();

        self::assertFalse($log->isEncrypted());

        $result = $log->setIsEncrypted(true);

        self::assertSame($log, $result);
        self::assertTrue($log->isEncrypted());

        $log->setIsEncrypted(false);
        self::assertFalse($log->isEncrypted());
    }
}
