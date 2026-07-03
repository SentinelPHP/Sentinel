<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\DataProtection;

use App\Entity\ApiToken;
use App\Enum\DataProtectionStrategy;
use App\Service\DataProtection\DataProtectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use SentinelPHP\Encrypt\EncryptorInterface;
use SentinelPHP\Redact\PiiRedactorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DataProtectionService::class)]
final class DataProtectionServiceTest extends TestCase
{
    public function testProtectWithNoneStrategyReturnsUnchangedData(): void
    {
        $piiRedactor = $this->createMock(PiiRedactorInterface::class);
        $dataEncryption = $this->createMock(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');
        $data = '{"email": "test@example.com"}';

        $piiRedactor->expects(self::never())->method('redact');
        $dataEncryption->expects(self::never())->method('encrypt');

        $result = $service->protect($data, DataProtectionStrategy::None);

        self::assertSame($data, $result);
    }

    public function testProtectWithRedactStrategyCallsRedactor(): void
    {
        $piiRedactor = $this->createMock(PiiRedactorInterface::class);
        $dataEncryption = $this->createMock(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');
        $data = '{"email": "test@example.com"}';
        $redacted = '{"email": "t***@example.com"}';

        $piiRedactor
            ->expects(self::once())
            ->method('redact')
            ->with($data, null, null)
            ->willReturn($redacted);

        $dataEncryption->expects(self::never())->method('encrypt');

        $result = $service->protect($data, DataProtectionStrategy::Redact);

        self::assertSame($redacted, $result);
    }

    public function testProtectWithEncryptStrategyCallsEncryption(): void
    {
        $piiRedactor = $this->createMock(PiiRedactorInterface::class);
        $dataEncryption = $this->createMock(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');
        $data = '{"email": "test@example.com"}';
        $encrypted = 'base64encrypteddata==';

        $dataEncryption
            ->expects(self::once())
            ->method('isEnabled')
            ->willReturn(true);

        $dataEncryption
            ->expects(self::once())
            ->method('encrypt')
            ->with($data)
            ->willReturn($encrypted);

        $piiRedactor->expects(self::never())->method('redact');

        $result = $service->protect($data, DataProtectionStrategy::Encrypt);

        self::assertSame($encrypted, $result);
    }

    public function testProtectWithRedactEncryptStrategyCallsBoth(): void
    {
        $piiRedactor = $this->createMock(PiiRedactorInterface::class);
        $dataEncryption = $this->createMock(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');
        $data = '{"email": "test@example.com"}';
        $redacted = '{"email": "t***@example.com"}';
        $encrypted = 'base64encrypteddata==';

        $piiRedactor
            ->expects(self::once())
            ->method('redact')
            ->with($data, null, null)
            ->willReturn($redacted);

        $dataEncryption
            ->expects(self::once())
            ->method('isEnabled')
            ->willReturn(true);

        $dataEncryption
            ->expects(self::once())
            ->method('encrypt')
            ->with($redacted)
            ->willReturn($encrypted);

        $result = $service->protect($data, DataProtectionStrategy::RedactEncrypt);

        self::assertSame($encrypted, $result);
    }

    public function testProtectWithCustomPatterns(): void
    {
        $piiRedactor = $this->createMock(PiiRedactorInterface::class);
        $dataEncryption = $this->createStub(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');
        $data = '{"custom": "value"}';
        $redacted = '{"custom": "[REDACTED]"}';
        $customPatterns = ['/value/' => '[REDACTED]'];

        $piiRedactor
            ->expects(self::once())
            ->method('redact')
            ->with($data, null, $customPatterns)
            ->willReturn($redacted);

        $result = $service->protect($data, DataProtectionStrategy::Redact, $customPatterns);

        self::assertSame($redacted, $result);
    }

    public function testProtectEmptyStringReturnsEmpty(): void
    {
        $piiRedactor = $this->createMock(PiiRedactorInterface::class);
        $dataEncryption = $this->createMock(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');

        $piiRedactor->expects(self::never())->method('redact');
        $dataEncryption->expects(self::never())->method('encrypt');

        $result = $service->protect('', DataProtectionStrategy::RedactEncrypt);

        self::assertSame('', $result);
    }

    public function testProtectThrowsWhenEncryptionRequestedButNotEnabled(): void
    {
        $piiRedactor = $this->createStub(PiiRedactorInterface::class);
        $dataEncryption = $this->createMock(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');

        $dataEncryption
            ->expects(self::once())
            ->method('isEnabled')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SENTINEL_ENCRYPTION_KEY is not configured');

        $service->protect('data', DataProtectionStrategy::Encrypt);
    }

    public function testRetrieveDecryptsWhenEncrypted(): void
    {
        $piiRedactor = $this->createStub(PiiRedactorInterface::class);
        $dataEncryption = $this->createMock(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');
        $encrypted = 'base64encrypteddata==';
        $decrypted = '{"email": "t***@example.com"}';

        $dataEncryption
            ->expects(self::once())
            ->method('decrypt')
            ->with($encrypted)
            ->willReturn($decrypted);

        $result = $service->retrieve($encrypted, true);

        self::assertSame($decrypted, $result);
    }

    public function testRetrieveReturnsUnchangedWhenNotEncrypted(): void
    {
        $piiRedactor = $this->createStub(PiiRedactorInterface::class);
        $dataEncryption = $this->createMock(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');
        $data = '{"email": "t***@example.com"}';

        $dataEncryption->expects(self::never())->method('decrypt');

        $result = $service->retrieve($data, false);

        self::assertSame($data, $result);
    }

    public function testRetrieveEmptyStringReturnsEmpty(): void
    {
        $piiRedactor = $this->createStub(PiiRedactorInterface::class);
        $dataEncryption = $this->createMock(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');

        $dataEncryption->expects(self::never())->method('decrypt');

        $result = $service->retrieve('', true);

        self::assertSame('', $result);
    }

    public function testGetEffectiveStrategyReturnsTokenStrategyWhenSet(): void
    {
        $service = $this->createServiceWithStubs('none');

        $token = $this->createStub(ApiToken::class);
        $token->method('getDataProtectionStrategy')->willReturn(DataProtectionStrategy::Encrypt);

        $result = $service->getEffectiveStrategy($token);

        self::assertSame(DataProtectionStrategy::Encrypt, $result);
    }

    public function testGetEffectiveStrategyReturnsDefaultWhenTokenHasNoStrategy(): void
    {
        $service = $this->createServiceWithStubs('redact');

        $token = $this->createStub(ApiToken::class);
        $token->method('getDataProtectionStrategy')->willReturn(null);

        $result = $service->getEffectiveStrategy($token);

        self::assertSame(DataProtectionStrategy::Redact, $result);
    }

    public function testGetEffectiveStrategyReturnsDefaultWhenTokenIsNull(): void
    {
        $service = $this->createServiceWithStubs('encrypt');

        $result = $service->getEffectiveStrategy(null);

        self::assertSame(DataProtectionStrategy::Encrypt, $result);
    }

    #[DataProvider('defaultStrategyProvider')]
    public function testConstructorParsesDefaultStrategy(string $strategyValue, DataProtectionStrategy $expected): void
    {
        $service = $this->createServiceWithStubs($strategyValue);

        $result = $service->getEffectiveStrategy(null);

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, DataProtectionStrategy}>
     */
    public static function defaultStrategyProvider(): iterable
    {
        yield 'none' => ['none', DataProtectionStrategy::None];
        yield 'redact' => ['redact', DataProtectionStrategy::Redact];
        yield 'encrypt' => ['encrypt', DataProtectionStrategy::Encrypt];
        yield 'redact_encrypt' => ['redact_encrypt', DataProtectionStrategy::RedactEncrypt];
        yield 'invalid falls back to none' => ['invalid', DataProtectionStrategy::None];
        yield 'empty falls back to none' => ['', DataProtectionStrategy::None];
    }

    public function testIsEncryptionAvailableReturnsTrue(): void
    {
        $piiRedactor = $this->createStub(PiiRedactorInterface::class);
        $dataEncryption = $this->createMock(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');

        $dataEncryption
            ->expects(self::once())
            ->method('isEnabled')
            ->willReturn(true);

        self::assertTrue($service->isEncryptionAvailable());
    }

    public function testIsEncryptionAvailableReturnsFalse(): void
    {
        $piiRedactor = $this->createStub(PiiRedactorInterface::class);
        $dataEncryption = $this->createMock(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');

        $dataEncryption
            ->expects(self::once())
            ->method('isEnabled')
            ->willReturn(false);

        self::assertFalse($service->isEncryptionAvailable());
    }

    public function testProtectHandlesRedactorReturningArray(): void
    {
        $piiRedactor = $this->createMock(PiiRedactorInterface::class);
        $dataEncryption = $this->createStub(EncryptorInterface::class);
        $service = new DataProtectionService($piiRedactor, $dataEncryption, 'none');
        $data = '{"email": "test@example.com"}';
        $redactedArray = ['email' => 't***@example.com'];

        $piiRedactor
            ->expects(self::once())
            ->method('redact')
            ->with($data, null, null)
            ->willReturn($redactedArray);

        $result = $service->protect($data, DataProtectionStrategy::Redact);

        self::assertSame('{"email":"t***@example.com"}', $result);
    }

    private function createServiceWithStubs(string $defaultStrategy = 'none'): DataProtectionService
    {
        $piiRedactor = $this->createStub(PiiRedactorInterface::class);
        $dataEncryption = $this->createStub(EncryptorInterface::class);

        return new DataProtectionService(
            $piiRedactor,
            $dataEncryption,
            $defaultStrategy,
        );
    }
}
