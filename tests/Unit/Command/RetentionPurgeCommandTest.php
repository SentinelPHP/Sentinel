<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\RetentionPurgeCommand;
use App\Repository\DriftPayloadRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RetentionPurgeCommand::class)]
final class RetentionPurgeCommandTest extends TestCase
{
    private DriftPayloadRepositoryInterface&MockObject $repository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(DriftPayloadRepositoryInterface::class);

        $command = new RetentionPurgeCommand(
            $this->repository,
            defaultRetentionDays: 30,
            defaultBatchSize: 1000
        );

        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function itPurgesOldRecords(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('deleteOlderThan')
            ->with(
                self::callback(fn (\DateTimeImmutable $cutoff): bool => $cutoff < new \DateTimeImmutable('-29 days')
                    && $cutoff > new \DateTimeImmutable('-31 days')),
                1000
            )
            ->willReturn(150);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Purged 150 drift payload record(s)', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itRespectsCustomDaysOption(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('deleteOlderThan')
            ->with(
                self::callback(fn (\DateTimeImmutable $cutoff): bool => $cutoff < new \DateTimeImmutable('-6 days')
                    && $cutoff > new \DateTimeImmutable('-8 days')),
                1000
            )
            ->willReturn(50);

        $this->commandTester->execute(['--days' => '7']);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Retention period: 7 days', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itRespectsCustomBatchSizeOption(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::anything(), 500)
            ->willReturn(25);

        $this->commandTester->execute(['--batch-size' => '500']);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Batch size: 500', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itShowsCountInDryRunMode(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('countOlderThan')
            ->willReturn(200);

        $this->repository
            ->expects(self::never())
            ->method('deleteOlderThan');

        $this->commandTester->execute(['--dry-run' => true]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('DRY RUN: Would delete 200 drift payload record(s)', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itSkipsPurgeWhenDaysIsZero(): void
    {
        $this->repository
            ->expects(self::never())
            ->method('deleteOlderThan');

        $this->commandTester->execute(['--days' => '0']);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Retention policy is disabled', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itSkipsPurgeWhenDaysIsNegative(): void
    {
        $this->repository
            ->expects(self::never())
            ->method('deleteOlderThan');

        $this->commandTester->execute(['--days' => '-5']);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Retention policy is disabled', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itFailsWhenBatchSizeIsZero(): void
    {
        $this->repository
            ->expects(self::never())
            ->method('deleteOlderThan');

        $this->commandTester->execute(['--batch-size' => '0']);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Batch size must be greater than 0', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itFailsWhenBatchSizeIsNegative(): void
    {
        $this->repository
            ->expects(self::never())
            ->method('deleteOlderThan');

        $this->commandTester->execute(['--batch-size' => '-100']);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Batch size must be greater than 0', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itHandlesZeroRecordsDeleted(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('deleteOlderThan')
            ->willReturn(0);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Purged 0 drift payload record(s)', $this->commandTester->getDisplay());
    }
}
