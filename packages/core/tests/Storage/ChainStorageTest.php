<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Tests\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Core\Record\ApiCallRecord;
use SentinelPHP\Core\Storage\ChainStorage;
use SentinelPHP\Core\Storage\NullStorage;
use SentinelPHP\Core\Storage\StorageException;
use SentinelPHP\Core\Storage\StorageInterface;

#[CoversClass(ChainStorage::class)]
#[AllowMockObjectsWithoutExpectations]
final class ChainStorageTest extends TestCase
{
    #[Test]
    public function it_calls_all_storages(): void
    {
        $storage1Called = false;
        $storage2Called = false;

        $storage1 = $this->createMock(StorageInterface::class);
        $storage1->expects(self::once())
            ->method('store')
            ->willReturnCallback(function () use (&$storage1Called): void {
                $storage1Called = true;
            });

        $storage2 = $this->createMock(StorageInterface::class);
        $storage2->expects(self::once())
            ->method('store')
            ->willReturnCallback(function () use (&$storage2Called): void {
                $storage2Called = true;
            });

        $chain = new ChainStorage($storage1, $storage2);
        $chain->store($this->createRecord());

        self::assertTrue($storage1Called);
        self::assertTrue($storage2Called);
    }

    #[Test]
    public function it_collects_errors_from_all_storages(): void
    {
        $storage1 = $this->createMock(StorageInterface::class);
        $storage1->method('store')
            ->willThrowException(StorageException::storeFailed('Storage 1 failed'));

        $storage2 = $this->createMock(StorageInterface::class);
        $storage2->method('store')
            ->willThrowException(StorageException::storeFailed('Storage 2 failed'));

        $chain = new ChainStorage($storage1, $storage2);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Storage 1 failed');
        $this->expectExceptionMessage('Storage 2 failed');

        $chain->store($this->createRecord());
    }

    #[Test]
    public function it_continues_after_single_failure(): void
    {
        $storage2Called = false;

        $storage1 = $this->createMock(StorageInterface::class);
        $storage1->method('store')
            ->willThrowException(StorageException::storeFailed('Storage 1 failed'));

        $storage2 = $this->createMock(StorageInterface::class);
        $storage2->expects(self::once())
            ->method('store')
            ->willReturnCallback(function () use (&$storage2Called): void {
                $storage2Called = true;
            });

        $chain = new ChainStorage($storage1, $storage2);

        try {
            $chain->store($this->createRecord());
        } catch (StorageException) {
            // Expected
        }

        self::assertTrue($storage2Called, 'Storage 2 should still be called after Storage 1 fails');
    }

    #[Test]
    public function it_allows_adding_storages(): void
    {
        $addedStorageCalled = false;

        $addedStorage = $this->createMock(StorageInterface::class);
        $addedStorage->expects(self::once())
            ->method('store')
            ->willReturnCallback(function () use (&$addedStorageCalled): void {
                $addedStorageCalled = true;
            });

        $chain = new ChainStorage(new NullStorage());
        $chain->add($addedStorage);
        $chain->store($this->createRecord());

        self::assertTrue($addedStorageCalled);
    }

    #[Test]
    public function it_works_with_empty_chain(): void
    {
        $chain = new ChainStorage();
        $chain->store($this->createRecord());

        // No exception means success
        $this->addToAssertionCount(1);
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
