<?php

declare(strict_types=1);

namespace SentinelPHP\Encrypt\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Encrypt\Encryptor;
use SentinelPHP\Encrypt\Exception\EncryptionException;
use SentinelPHP\Encrypt\Exception\InvalidKeyException;

#[CoversClass(Encryptor::class)]
#[CoversClass(EncryptionException::class)]
#[CoversClass(InvalidKeyException::class)]
final class EncryptorTest extends TestCase
{
    #[Test]
    public function itGeneratesValidKey(): void
    {
        $key = Encryptor::generateKey();

        self::assertNotEmpty($key);
        self::assertSame(44, strlen($key)); // Base64 encoded 32 bytes
    }

    #[Test]
    public function itEncryptsAndDecryptsData(): void
    {
        $key = Encryptor::generateKey();
        $encryptor = new Encryptor($key);

        $plaintext = 'sensitive data';
        $ciphertext = $encryptor->encrypt($plaintext);

        self::assertNotSame($plaintext, $ciphertext);

        $decrypted = $encryptor->decrypt($ciphertext);
        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function itProducesDifferentCiphertextForSamePlaintext(): void
    {
        $key = Encryptor::generateKey();
        $encryptor = new Encryptor($key);

        $plaintext = 'sensitive data';
        $ciphertext1 = $encryptor->encrypt($plaintext);
        $ciphertext2 = $encryptor->encrypt($plaintext);

        self::assertNotSame($ciphertext1, $ciphertext2);
    }

    #[Test]
    public function itHandlesEmptyString(): void
    {
        $key = Encryptor::generateKey();
        $encryptor = new Encryptor($key);

        $ciphertext = $encryptor->encrypt('');
        $decrypted = $encryptor->decrypt($ciphertext);

        self::assertSame('', $decrypted);
    }

    #[Test]
    public function itHandlesLargeData(): void
    {
        $key = Encryptor::generateKey();
        $encryptor = new Encryptor($key);

        $plaintext = str_repeat('x', 1024 * 1024); // 1MB
        $ciphertext = $encryptor->encrypt($plaintext);
        $decrypted = $encryptor->decrypt($ciphertext);

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function itHandlesUnicodeData(): void
    {
        $key = Encryptor::generateKey();
        $encryptor = new Encryptor($key);

        $plaintext = '日本語テスト 🔐 émojis';
        $ciphertext = $encryptor->encrypt($plaintext);
        $decrypted = $encryptor->decrypt($ciphertext);

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function itThrowsExceptionForTamperedData(): void
    {
        $key = Encryptor::generateKey();
        $encryptor = new Encryptor($key);

        $ciphertext = $encryptor->encrypt('sensitive data');
        $tampered = substr($ciphertext, 0, -5) . 'XXXXX';

        $this->expectException(EncryptionException::class);
        $encryptor->decrypt($tampered);
    }

    #[Test]
    public function itThrowsExceptionForWrongKey(): void
    {
        $key1 = Encryptor::generateKey();
        $key2 = Encryptor::generateKey();

        $encryptor1 = new Encryptor($key1);
        $encryptor2 = new Encryptor($key2);

        $ciphertext = $encryptor1->encrypt('sensitive data');

        $this->expectException(EncryptionException::class);
        $encryptor2->decrypt($ciphertext);
    }

    #[Test]
    public function itReportsEnabledWhenKeyProvided(): void
    {
        $key = Encryptor::generateKey();
        $encryptor = new Encryptor($key);

        self::assertTrue($encryptor->isEnabled());
    }

    #[Test]
    public function itReportsDisabledWhenNoKey(): void
    {
        $encryptor = new Encryptor(null);

        self::assertFalse($encryptor->isEnabled());
    }

    #[Test]
    public function itThrowsExceptionWhenEncryptingWithoutKey(): void
    {
        $encryptor = new Encryptor(null);

        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessage('Encryption key not configured');
        $encryptor->encrypt('sensitive data');
    }

    #[Test]
    public function itThrowsExceptionWhenDecryptingWithoutKey(): void
    {
        $encryptor = new Encryptor(null);

        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessage('Encryption key not configured');
        $encryptor->decrypt('some data');
    }

    #[Test]
    public function itThrowsExceptionForInvalidCiphertext(): void
    {
        $key = Encryptor::generateKey();
        $encryptor = new Encryptor($key);

        $this->expectException(EncryptionException::class);
        $encryptor->decrypt('not-valid-base64!!!');
    }

    #[Test]
    public function itThrowsExceptionForTooShortCiphertext(): void
    {
        $key = Encryptor::generateKey();
        $encryptor = new Encryptor($key);

        $this->expectException(EncryptionException::class);
        $encryptor->decrypt(base64_encode('short'));
    }

    #[Test]
    public function itThrowsExceptionForInvalidBase64Key(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage('not valid base64');
        new Encryptor('not-valid-base64!!!');
    }

    #[Test]
    public function itThrowsExceptionForWrongLengthKey(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage('Invalid encryption key length');
        new Encryptor(base64_encode('tooshort'));
    }

    #[Test]
    public function itAcceptsEmptyStringAsDisabled(): void
    {
        $encryptor = new Encryptor('');
        self::assertFalse($encryptor->isEnabled());
    }

    #[Test]
    public function invalidKeyExceptionMissingKey(): void
    {
        $exception = InvalidKeyException::missingKey();
        self::assertStringContainsString('not configured', $exception->getMessage());
    }

    #[Test]
    public function encryptionExceptionFactoryMethods(): void
    {
        $e1 = EncryptionException::encryptionFailed('reason');
        self::assertStringContainsString('Encryption failed', $e1->getMessage());
        self::assertStringContainsString('reason', $e1->getMessage());

        $e2 = EncryptionException::encryptionFailed();
        self::assertStringContainsString('Encryption failed', $e2->getMessage());

        $e3 = EncryptionException::decryptionFailed('reason');
        self::assertStringContainsString('Decryption failed', $e3->getMessage());

        $e4 = EncryptionException::decryptionFailed();
        self::assertStringContainsString('Decryption failed', $e4->getMessage());

        $e5 = EncryptionException::invalidCiphertext();
        self::assertStringContainsString('Invalid ciphertext', $e5->getMessage());
    }
}
