<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\LogLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LogLevel::class)]
final class LogLevelTest extends TestCase
{
    #[Test]
    public function noneSkipsLogging(): void
    {
        self::assertTrue(LogLevel::None->shouldSkipLogging());
    }

    #[Test]
    #[DataProvider('nonSkippingLevelsProvider')]
    public function otherLevelsDoNotSkipLogging(LogLevel $level): void
    {
        self::assertFalse($level->shouldSkipLogging());
    }

    /**
     * @return iterable<string, array{level: LogLevel}>
     */
    public static function nonSkippingLevelsProvider(): iterable
    {
        yield 'metadata_only' => ['level' => LogLevel::MetadataOnly];
        yield 'drift_only' => ['level' => LogLevel::DriftOnly];
        yield 'headers' => ['level' => LogLevel::Headers];
        yield 'full_audit' => ['level' => LogLevel::FullAudit];
    }

    #[Test]
    public function noneLogsNoFields(): void
    {
        $fields = LogLevel::None->getLoggedFields();

        self::assertFalse($fields['requestHeaders']);
        self::assertFalse($fields['requestBody']);
        self::assertFalse($fields['responseHeaders']);
        self::assertFalse($fields['responseBody']);
    }

    #[Test]
    public function metadataOnlyLogsNoFields(): void
    {
        $fields = LogLevel::MetadataOnly->getLoggedFields();

        self::assertFalse($fields['requestHeaders']);
        self::assertFalse($fields['requestBody']);
        self::assertFalse($fields['responseHeaders']);
        self::assertFalse($fields['responseBody']);
    }

    #[Test]
    public function driftOnlyLogsNoFieldsInitially(): void
    {
        $fields = LogLevel::DriftOnly->getLoggedFields();

        self::assertFalse($fields['requestHeaders']);
        self::assertFalse($fields['requestBody']);
        self::assertFalse($fields['responseHeaders']);
        self::assertFalse($fields['responseBody']);
    }

    #[Test]
    public function driftOnlyShouldLogBodiesOnDrift(): void
    {
        self::assertTrue(LogLevel::DriftOnly->shouldLogBodiesOnDrift());
    }

    #[Test]
    #[DataProvider('nonDriftOnlyLevelsProvider')]
    public function otherLevelsDoNotLogBodiesOnDrift(LogLevel $level): void
    {
        self::assertFalse($level->shouldLogBodiesOnDrift());
    }

    /**
     * @return iterable<string, array{level: LogLevel}>
     */
    public static function nonDriftOnlyLevelsProvider(): iterable
    {
        yield 'none' => ['level' => LogLevel::None];
        yield 'metadata_only' => ['level' => LogLevel::MetadataOnly];
        yield 'headers' => ['level' => LogLevel::Headers];
        yield 'full_audit' => ['level' => LogLevel::FullAudit];
    }

    #[Test]
    public function headersLogsOnlyHeaders(): void
    {
        $fields = LogLevel::Headers->getLoggedFields();

        self::assertTrue($fields['requestHeaders']);
        self::assertFalse($fields['requestBody']);
        self::assertTrue($fields['responseHeaders']);
        self::assertFalse($fields['responseBody']);
    }

    #[Test]
    public function fullAuditLogsAllFields(): void
    {
        $fields = LogLevel::FullAudit->getLoggedFields();

        self::assertTrue($fields['requestHeaders']);
        self::assertTrue($fields['requestBody']);
        self::assertTrue($fields['responseHeaders']);
        self::assertTrue($fields['responseBody']);
    }

    #[Test]
    public function valuesReturnsAllStringValues(): void
    {
        $values = LogLevel::values();

        self::assertContains('none', $values);
        self::assertContains('metadata_only', $values);
        self::assertContains('drift_only', $values);
        self::assertContains('headers', $values);
        self::assertContains('full_audit', $values);
        self::assertCount(5, $values);
    }

    #[Test]
    public function enumCanBeCreatedFromString(): void
    {
        self::assertSame(LogLevel::None, LogLevel::from('none'));
        self::assertSame(LogLevel::MetadataOnly, LogLevel::from('metadata_only'));
        self::assertSame(LogLevel::DriftOnly, LogLevel::from('drift_only'));
        self::assertSame(LogLevel::Headers, LogLevel::from('headers'));
        self::assertSame(LogLevel::FullAudit, LogLevel::from('full_audit'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        /** @var string $invalidValue */
        $invalidValue = 'invalid';
        self::assertNull(LogLevel::tryFrom($invalidValue));
    }
}
