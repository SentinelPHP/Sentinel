<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Alert;

use App\ValueObject\AlertResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlertResult::class)]
final class AlertResultTest extends TestCase
{
    #[Test]
    public function itCreatesSuccessResult(): void
    {
        $result = AlertResult::success('slack');

        self::assertTrue($result->isSuccess());
        self::assertFalse($result->isFailure());
        self::assertSame('slack', $result->channelName);
        self::assertNull($result->errorMessage);
        self::assertInstanceOf(\DateTimeImmutable::class, $result->sentAt);
    }

    #[Test]
    public function itCreatesFailureResult(): void
    {
        $result = AlertResult::failure('webhook', 'Connection timeout');

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->isFailure());
        self::assertSame('webhook', $result->channelName);
        self::assertSame('Connection timeout', $result->errorMessage);
        self::assertInstanceOf(\DateTimeImmutable::class, $result->sentAt);
    }

    #[Test]
    public function itRecordsSentAtTimestamp(): void
    {
        $before = new \DateTimeImmutable();
        $result = AlertResult::success('email');
        $after = new \DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $result->sentAt);
        self::assertLessThanOrEqual($after, $result->sentAt);
    }
}
