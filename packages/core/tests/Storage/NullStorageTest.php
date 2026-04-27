<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Tests\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Core\Record\ApiCallRecord;
use SentinelPHP\Core\Storage\NullStorage;

#[CoversClass(NullStorage::class)]
final class NullStorageTest extends TestCase
{
    #[Test]
    public function it_accepts_records_without_error(): void
    {
        $storage = new NullStorage();

        $record = new ApiCallRecord(
            method: 'GET',
            url: 'https://api.example.com/test',
            statusCode: 200,
            latencyMs: 50.0,
            timestamp: new DateTimeImmutable(),
        );

        $storage->store($record);

        // No exception means success
        $this->addToAssertionCount(1);
    }
}
