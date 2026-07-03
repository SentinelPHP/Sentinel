<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\DataProtectionStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DataProtectionStrategy::class)]
final class DataProtectionStrategyTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        $cases = DataProtectionStrategy::cases();

        self::assertCount(4, $cases);
        self::assertContains(DataProtectionStrategy::None, $cases);
        self::assertContains(DataProtectionStrategy::Redact, $cases);
        self::assertContains(DataProtectionStrategy::Encrypt, $cases);
        self::assertContains(DataProtectionStrategy::RedactEncrypt, $cases);
    }

    #[Test]
    public function valuesReturnsAllStringValues(): void
    {
        $values = DataProtectionStrategy::values();

        self::assertContains('none', $values);
        self::assertContains('redact', $values);
        self::assertContains('encrypt', $values);
        self::assertContains('redact_encrypt', $values);
        self::assertCount(4, $values);
    }

    #[Test]
    public function enumCanBeCreatedFromString(): void
    {
        self::assertSame(DataProtectionStrategy::None, DataProtectionStrategy::from('none'));
        self::assertSame(DataProtectionStrategy::Redact, DataProtectionStrategy::from('redact'));
        self::assertSame(DataProtectionStrategy::Encrypt, DataProtectionStrategy::from('encrypt'));
        self::assertSame(DataProtectionStrategy::RedactEncrypt, DataProtectionStrategy::from('redact_encrypt'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        /** @var string $invalidValue */
        $invalidValue = 'invalid';
        self::assertNull(DataProtectionStrategy::tryFrom($invalidValue));
    }

    #[Test]
    #[DataProvider('shouldRedactProvider')]
    public function shouldRedactReturnsCorrectValue(DataProtectionStrategy $strategy, bool $expected): void
    {
        self::assertSame($expected, $strategy->shouldRedact());
    }

    /**
     * @return iterable<string, array{strategy: DataProtectionStrategy, expected: bool}>
     */
    public static function shouldRedactProvider(): iterable
    {
        yield 'none does not redact' => [
            'strategy' => DataProtectionStrategy::None,
            'expected' => false,
        ];
        yield 'redact does redact' => [
            'strategy' => DataProtectionStrategy::Redact,
            'expected' => true,
        ];
        yield 'encrypt does not redact' => [
            'strategy' => DataProtectionStrategy::Encrypt,
            'expected' => false,
        ];
        yield 'redact_encrypt does redact' => [
            'strategy' => DataProtectionStrategy::RedactEncrypt,
            'expected' => true,
        ];
    }

    #[Test]
    #[DataProvider('shouldEncryptProvider')]
    public function shouldEncryptReturnsCorrectValue(DataProtectionStrategy $strategy, bool $expected): void
    {
        self::assertSame($expected, $strategy->shouldEncrypt());
    }

    /**
     * @return iterable<string, array{strategy: DataProtectionStrategy, expected: bool}>
     */
    public static function shouldEncryptProvider(): iterable
    {
        yield 'none does not encrypt' => [
            'strategy' => DataProtectionStrategy::None,
            'expected' => false,
        ];
        yield 'redact does not encrypt' => [
            'strategy' => DataProtectionStrategy::Redact,
            'expected' => false,
        ];
        yield 'encrypt does encrypt' => [
            'strategy' => DataProtectionStrategy::Encrypt,
            'expected' => true,
        ];
        yield 'redact_encrypt does encrypt' => [
            'strategy' => DataProtectionStrategy::RedactEncrypt,
            'expected' => true,
        ];
    }
}
