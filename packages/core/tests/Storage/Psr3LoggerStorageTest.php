<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Tests\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use SentinelPHP\Core\Record\ApiCallRecord;
use SentinelPHP\Core\Storage\Psr3LoggerStorage;

#[CoversClass(Psr3LoggerStorage::class)]
final class Psr3LoggerStorageTest extends TestCase
{
    #[Test]
    public function it_logs_record_with_default_level(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(
                LogLevel::INFO,
                'API Call: GET https://api.example.com/users -> 200',
                self::callback(function (array $context): bool {
                    return $context['method'] === 'GET'
                        && $context['url'] === 'https://api.example.com/users'
                        && $context['status_code'] === 200
                        && $context['latency_ms'] === 50.0;
                })
            );

        $storage = new Psr3LoggerStorage($logger);
        $storage->store($this->createRecord());
    }

    #[Test]
    public function it_logs_record_with_custom_level(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(
                LogLevel::DEBUG,
                self::anything(),
                self::anything()
            );

        $storage = new Psr3LoggerStorage($logger, LogLevel::DEBUG);
        $storage->store($this->createRecord());
    }

    #[Test]
    public function it_includes_body_by_default(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(function (array $context): bool {
                    return array_key_exists('request_body', $context)
                        && array_key_exists('response_body', $context);
                })
            );

        $storage = new Psr3LoggerStorage($logger);
        $storage->store($this->createRecordWithBody());
    }

    #[Test]
    public function it_excludes_body_when_configured(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(function (array $context): bool {
                    return !array_key_exists('request_body', $context)
                        && !array_key_exists('response_body', $context);
                })
            );

        $storage = new Psr3LoggerStorage($logger, LogLevel::INFO, includeBody: false);
        $storage->store($this->createRecordWithBody());
    }

    #[Test]
    public function it_includes_schema_when_present(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(function (array $context): bool {
                    return isset($context['schema']) && $context['schema'] === ['type' => 'object'];
                })
            );

        $storage = new Psr3LoggerStorage($logger);
        $storage->store($this->createRecordWithSchema());
    }

    private function createRecord(): ApiCallRecord
    {
        return new ApiCallRecord(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            latencyMs: 50.0,
            timestamp: new DateTimeImmutable(),
        );
    }

    private function createRecordWithBody(): ApiCallRecord
    {
        return new ApiCallRecord(
            method: 'POST',
            url: 'https://api.example.com/users',
            statusCode: 201,
            latencyMs: 100.0,
            timestamp: new DateTimeImmutable(),
            requestBody: '{"name":"John"}',
            responseBody: '{"id":1}',
        );
    }

    private function createRecordWithSchema(): ApiCallRecord
    {
        return new ApiCallRecord(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            latencyMs: 50.0,
            timestamp: new DateTimeImmutable(),
            generatedSchema: ['type' => 'object'],
        );
    }
}
