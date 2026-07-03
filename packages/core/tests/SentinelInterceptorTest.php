<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Tests;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Core\Config\InterceptorConfig;
use SentinelPHP\Core\Record\ApiCallRecord;
use SentinelPHP\Core\SentinelInterceptor;
use SentinelPHP\Core\Storage\StorageInterface;
use SentinelPHP\Redact\PiiRedactorInterface;
use SentinelPHP\Schema\GeneratorInterface;

#[CoversClass(SentinelInterceptor::class)]
#[AllowMockObjectsWithoutExpectations]
final class SentinelInterceptorTest extends TestCase
{
    #[Test]
    public function it_stores_intercepted_call(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())
            ->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $interceptor = new SentinelInterceptor($storage);

        $result = $interceptor->intercept(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            latencyMs: 50.0,
            requestHeaders: ['Accept' => 'application/json'],
            requestBody: null,
            responseHeaders: ['Content-Type' => 'application/json'],
            responseBody: '{"users":[]}',
            id: 'test-id',
        );

        self::assertNotNull($capturedRecord);
        self::assertSame('GET', $capturedRecord->method);
        self::assertSame('https://api.example.com/users', $capturedRecord->url);
        self::assertSame(200, $capturedRecord->statusCode);
        self::assertSame(50.0, $capturedRecord->latencyMs);
        self::assertSame('test-id', $capturedRecord->id);
        self::assertSame($result, $capturedRecord);
    }

    #[Test]
    public function it_redacts_pii_when_enabled(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $redactor = $this->createMock(PiiRedactorInterface::class);
        $redactor->method('redact')
            ->willReturn(['email' => '[REDACTED]']);

        $config = new InterceptorConfig(redactPii: true);
        $interceptor = new SentinelInterceptor($storage, $config, $redactor);

        $interceptor->intercept(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            latencyMs: 50.0,
            responseBody: '{"email":"john@example.com"}',
        );

        self::assertNotNull($capturedRecord);
        self::assertSame('{"email":"[REDACTED]"}', $capturedRecord->responseBody);
    }

    #[Test]
    public function it_skips_redaction_when_disabled(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $redactor = $this->createMock(PiiRedactorInterface::class);
        $redactor->expects(self::never())->method('redact');

        $config = new InterceptorConfig(redactPii: false);
        $interceptor = new SentinelInterceptor($storage, $config, $redactor);

        $interceptor->intercept(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            latencyMs: 50.0,
            responseBody: '{"email":"john@example.com"}',
        );

        self::assertNotNull($capturedRecord);
        self::assertSame('{"email":"john@example.com"}', $capturedRecord->responseBody);
    }

    #[Test]
    public function it_generates_schema_when_enabled(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $generator = $this->createMock(GeneratorInterface::class);
        $generator->method('generate')
            ->willReturn(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]);

        $config = new InterceptorConfig(redactPii: false, generateSchemas: true);
        $interceptor = new SentinelInterceptor($storage, $config, schemaGenerator: $generator);

        $interceptor->intercept(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            latencyMs: 50.0,
            responseBody: '{"id":1}',
        );

        self::assertNotNull($capturedRecord);
        self::assertSame(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]], $capturedRecord->generatedSchema);
    }

    #[Test]
    public function it_skips_schema_generation_when_disabled(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $generator = $this->createMock(GeneratorInterface::class);
        $generator->expects(self::never())->method('generate');

        $config = new InterceptorConfig(generateSchemas: false);
        $interceptor = new SentinelInterceptor($storage, $config, schemaGenerator: $generator);

        $interceptor->intercept(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            latencyMs: 50.0,
            responseBody: '{"id":1}',
        );

        self::assertNotNull($capturedRecord);
        self::assertNull($capturedRecord->generatedSchema);
    }

    #[Test]
    public function it_excludes_body_when_configured(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $config = new InterceptorConfig(
            captureRequestBody: false,
            captureResponseBody: false,
        );
        $interceptor = new SentinelInterceptor($storage, $config);

        $interceptor->intercept(
            method: 'POST',
            url: 'https://api.example.com/users',
            statusCode: 201,
            latencyMs: 100.0,
            requestBody: '{"name":"John"}',
            responseBody: '{"id":1}',
        );

        self::assertNotNull($capturedRecord);
        self::assertNull($capturedRecord->requestBody);
        self::assertNull($capturedRecord->responseBody);
    }

    #[Test]
    public function it_excludes_headers_when_configured(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $config = new InterceptorConfig(captureHeaders: false);
        $interceptor = new SentinelInterceptor($storage, $config);

        $interceptor->intercept(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            latencyMs: 50.0,
            requestHeaders: ['Authorization' => 'Bearer token'],
            responseHeaders: ['X-Request-Id' => 'abc123'],
        );

        self::assertNotNull($capturedRecord);
        self::assertSame([], $capturedRecord->requestHeaders);
        self::assertSame([], $capturedRecord->responseHeaders);
    }

    #[Test]
    public function it_redacts_non_json_strings(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $redactor = $this->createMock(PiiRedactorInterface::class);
        $redactor->method('redactString')
            ->with('Contact: john@example.com')
            ->willReturn('Contact: [EMAIL REDACTED]');

        $config = new InterceptorConfig(redactPii: true);
        $interceptor = new SentinelInterceptor($storage, $config, $redactor);

        $interceptor->intercept(
            method: 'GET',
            url: 'https://api.example.com/contact',
            statusCode: 200,
            latencyMs: 50.0,
            responseBody: 'Contact: john@example.com',
        );

        self::assertNotNull($capturedRecord);
        self::assertSame('Contact: [EMAIL REDACTED]', $capturedRecord->responseBody);
    }

    #[Test]
    public function it_exposes_config_and_storage(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $config = InterceptorConfig::full();

        $interceptor = new SentinelInterceptor($storage, $config);

        self::assertSame($config, $interceptor->getConfig());
        self::assertSame($storage, $interceptor->getStorage());
    }

    #[Test]
    public function it_redacts_both_request_and_response_bodies(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $redactor = $this->createMock(PiiRedactorInterface::class);
        $redactor->method('redact')
            ->willReturnCallback(function (array $data): array {
                return array_map(fn ($v) => is_string($v) && str_contains($v, '@') ? '[REDACTED]' : $v, $data);
            });

        $config = new InterceptorConfig(redactPii: true);
        $interceptor = new SentinelInterceptor($storage, $config, $redactor);

        $interceptor->intercept(
            method: 'POST',
            url: 'https://api.example.com/users',
            statusCode: 201,
            latencyMs: 50.0,
            requestBody: '{"email":"user@example.com"}',
            responseBody: '{"email":"user@example.com","id":1}',
        );

        self::assertNotNull($capturedRecord);
        self::assertSame('{"email":"[REDACTED]"}', $capturedRecord->requestBody);
        self::assertSame('{"email":"[REDACTED]","id":1}', $capturedRecord->responseBody);
    }

    #[Test]
    public function it_uses_custom_redact_field_paths(): void
    {
        $capturedRecord = null;
        $capturedPaths = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $redactor = $this->createMock(PiiRedactorInterface::class);
        $redactor->method('redact')
            ->willReturnCallback(function (array $data, ?array $paths) use (&$capturedPaths): array {
                $capturedPaths = $paths;
                return ['password' => '[REDACTED]', 'api_key' => '[REDACTED]'];
            });

        $config = new InterceptorConfig(
            redactPii: true,
            redactFieldPaths: ['password', 'api_key'],
        );
        $interceptor = new SentinelInterceptor($storage, $config, $redactor);

        $interceptor->intercept(
            method: 'POST',
            url: 'https://api.example.com/auth',
            statusCode: 200,
            latencyMs: 50.0,
            responseBody: '{"password":"secret","api_key":"abc123"}',
        );

        self::assertNotNull($capturedRecord);
        self::assertSame(['password', 'api_key'], $capturedPaths);
    }
}
