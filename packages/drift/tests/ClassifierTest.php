<?php

declare(strict_types=1);

namespace SentinelPHP\Drift\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Drift\Classifier;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;

#[CoversClass(Classifier::class)]
final class ClassifierTest extends TestCase
{
    private Classifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new Classifier();
    }

    #[Test]
    public function itClassifiesFieldRemovedAsCritical(): void
    {
        $severity = $this->classifier->classify(DriftType::FieldRemoved, ['email'], null);

        self::assertSame(DriftSeverity::Critical, $severity);
    }

    #[Test]
    public function itClassifiesFieldAddedAsInfo(): void
    {
        $severity = $this->classifier->classify(DriftType::FieldAdded, false, ['extra_field']);

        self::assertSame(DriftSeverity::Info, $severity);
    }

    #[Test]
    public function itClassifiesObjectToPrimitiveTypeChangeAsCritical(): void
    {
        $severity = $this->classifier->classify(DriftType::TypeChanged, 'object', 'string');

        self::assertSame(DriftSeverity::Critical, $severity);
    }

    #[Test]
    public function itClassifiesArrayToPrimitiveTypeChangeAsCritical(): void
    {
        $severity = $this->classifier->classify(DriftType::TypeChanged, 'array', 'integer');

        self::assertSame(DriftSeverity::Critical, $severity);
    }

    #[Test]
    public function itClassifiesObjectToNullTypeChangeAsCritical(): void
    {
        $severity = $this->classifier->classify(DriftType::TypeChanged, 'object', 'null');

        self::assertSame(DriftSeverity::Critical, $severity);
    }

    #[Test]
    #[DataProvider('compatibleTypeChangesProvider')]
    public function itClassifiesCompatibleTypeChangesAsWarning(mixed $expected, mixed $actual): void
    {
        $severity = $this->classifier->classify(DriftType::TypeChanged, $expected, $actual);

        self::assertSame(DriftSeverity::Warning, $severity);
    }

    /**
     * @return iterable<string, array{expected: string, actual: string}>
     */
    public static function compatibleTypeChangesProvider(): iterable
    {
        yield 'integer to number' => ['expected' => 'integer', 'actual' => 'number'];
        yield 'number to integer' => ['expected' => 'number', 'actual' => 'integer'];
        yield 'string to integer' => ['expected' => 'string', 'actual' => 'integer'];
        yield 'boolean to string' => ['expected' => 'boolean', 'actual' => 'string'];
    }

    #[Test]
    public function itClassifiesStructureChangedAsWarning(): void
    {
        $severity = $this->classifier->classify(DriftType::StructureChanged, 'date-time', 'date');

        self::assertSame(DriftSeverity::Warning, $severity);
    }

    #[Test]
    public function itHandlesWrappedTypeFormat(): void
    {
        $severity = $this->classifier->classify(
            DriftType::TypeChanged,
            ['type' => 'object'],
            ['type' => 'string']
        );

        self::assertSame(DriftSeverity::Critical, $severity);
    }

    #[Test]
    public function itHandlesNullExpectedValue(): void
    {
        $severity = $this->classifier->classify(DriftType::TypeChanged, null, 'string');

        self::assertSame(DriftSeverity::Warning, $severity);
    }

    #[Test]
    public function itHandlesNullActualValue(): void
    {
        $severity = $this->classifier->classify(DriftType::TypeChanged, 'string', null);

        self::assertSame(DriftSeverity::Warning, $severity);
    }

    #[Test]
    public function itClassifiesFormatChangeAsInfo(): void
    {
        $severity = $this->classifier->classify(
            DriftType::TypeChanged,
            ['type' => 'string', 'format' => 'date-time'],
            ['type' => 'string', 'format' => 'date']
        );

        self::assertSame(DriftSeverity::Info, $severity);
    }

    #[Test]
    public function itAppliesSeverityOverrides(): void
    {
        $overrides = [
            DriftType::FieldRemoved->value => DriftSeverity::Warning->value,
            DriftType::FieldAdded->value => DriftSeverity::Critical->value,
        ];

        $severity = $this->classifier->classify(DriftType::FieldRemoved, ['email'], null, $overrides);
        self::assertSame(DriftSeverity::Warning, $severity);

        $severity = $this->classifier->classify(DriftType::FieldAdded, null, ['extra'], $overrides);
        self::assertSame(DriftSeverity::Critical, $severity);
    }

    #[Test]
    public function itIgnoresInvalidOverrideValues(): void
    {
        $overrides = [
            DriftType::FieldRemoved->value => 'invalid_severity',
        ];

        $severity = $this->classifier->classify(DriftType::FieldRemoved, ['email'], null, $overrides);

        self::assertSame(DriftSeverity::Critical, $severity);
    }

    #[Test]
    public function itShouldAlertWhenSeverityMeetsThreshold(): void
    {
        self::assertTrue($this->classifier->shouldAlert(DriftSeverity::Critical));
        self::assertTrue($this->classifier->shouldAlert(DriftSeverity::Warning));
        self::assertFalse($this->classifier->shouldAlert(DriftSeverity::Info));
    }

    #[Test]
    public function itShouldAlertWithCustomDefaultThreshold(): void
    {
        $classifier = new Classifier(DriftSeverity::Critical);

        self::assertTrue($classifier->shouldAlert(DriftSeverity::Critical));
        self::assertFalse($classifier->shouldAlert(DriftSeverity::Warning));
        self::assertFalse($classifier->shouldAlert(DriftSeverity::Info));
    }

    #[Test]
    public function itShouldAlertWithThresholdOverride(): void
    {
        self::assertTrue($this->classifier->shouldAlert(DriftSeverity::Critical, DriftSeverity::Info));
        self::assertTrue($this->classifier->shouldAlert(DriftSeverity::Warning, DriftSeverity::Info));
        self::assertTrue($this->classifier->shouldAlert(DriftSeverity::Info, DriftSeverity::Info));
    }

    #[Test]
    public function itShouldAlertWithCriticalOnlyThreshold(): void
    {
        self::assertTrue($this->classifier->shouldAlert(DriftSeverity::Critical, DriftSeverity::Critical));
        self::assertFalse($this->classifier->shouldAlert(DriftSeverity::Warning, DriftSeverity::Critical));
        self::assertFalse($this->classifier->shouldAlert(DriftSeverity::Info, DriftSeverity::Critical));
    }
}
