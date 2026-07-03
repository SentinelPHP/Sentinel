<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Tests\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SentinelPHP\Core\Record\ApiCallRecord;
use SentinelPHP\Core\Storage\CallbackStorage;
use SentinelPHP\Core\Storage\StorageException;

#[CoversClass(CallbackStorage::class)]
final class CallbackStorageTest extends TestCase
{
    #[Test]
    public function it_calls_callback_with_record(): void
    {
        $capturedRecord = null;

        $storage = new CallbackStorage(function (ApiCallRecord $record) use (&$capturedRecord): void {
            $capturedRecord = $record;
        });

        $record = $this->createRecord();
        $storage->store($record);

        self::assertSame($record, $capturedRecord);
    }

    #[Test]
    public function it_wraps_callback_exceptions_in_storage_exception(): void
    {
        $storage = new CallbackStorage(function (ApiCallRecord $record): void {
            throw new RuntimeException('Database connection failed');
        });

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Failed to store API call record: Database connection failed');

        $storage->store($this->createRecord());
    }

    private function createRecord(): ApiCallRecord
    {
        return new ApiCallRecord(
            method: 'GET',
            url: 'https://api.example.com/test',
            statusCode: 200,
            latencyMs: 50.0,
            timestamp: new DateTimeImmutable(),
        );
    }
}
