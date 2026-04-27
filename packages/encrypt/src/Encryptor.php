<?php

declare(strict_types=1);

namespace SentinelPHP\Encrypt;

use SentinelPHP\Encrypt\Exception\EncryptionException;
use SentinelPHP\Encrypt\Exception\InvalidKeyException;

final class Encryptor implements EncryptorInterface
{
    private const KEY_LENGTH = SODIUM_CRYPTO_SECRETBOX_KEYBYTES; // 32 bytes
    private const NONCE_LENGTH = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES; // 24 bytes

    private ?string $key;

    /**
     * @param string|null $encryptionKey Base64-encoded 32-byte encryption key, or null/empty to disable encryption
     * @throws InvalidKeyException If key is provided but invalid
     */
    public function __construct(?string $encryptionKey = null)
    {
        if ($encryptionKey === null || $encryptionKey === '') {
            $this->key = null;

            return;
        }

        $this->key = $this->decodeAndValidateKey($encryptionKey);
    }

    public function encrypt(string $plaintext): string
    {
        if ($this->key === null) {
            throw EncryptionException::encryptionFailed('Encryption key not configured');
        }

        $nonce = random_bytes(self::NONCE_LENGTH);

        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        // Prepend nonce to ciphertext and base64 encode
        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $ciphertext): string
    {
        if ($this->key === null) {
            throw EncryptionException::decryptionFailed('Encryption key not configured');
        }

        $decoded = base64_decode($ciphertext, true);

        if ($decoded === false) {
            throw EncryptionException::invalidCiphertext();
        }

        // Minimum length: nonce (24) + auth tag (16) = 40 bytes
        $minLength = self::NONCE_LENGTH + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if (strlen($decoded) < $minLength) {
            throw EncryptionException::invalidCiphertext();
        }

        $nonce = substr($decoded, 0, self::NONCE_LENGTH);
        $encrypted = substr($decoded, self::NONCE_LENGTH);

        $plaintext = sodium_crypto_secretbox_open($encrypted, $nonce, $this->key);

        if ($plaintext === false) {
            throw EncryptionException::decryptionFailed('Authentication failed or corrupted ciphertext');
        }

        return $plaintext;
    }

    public function isEnabled(): bool
    {
        return $this->key !== null;
    }

    /**
     * Generate a new random encryption key.
     *
     * @return string Base64-encoded 32-byte key
     */
    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    /**
     * @throws InvalidKeyException
     */
    private function decodeAndValidateKey(string $encodedKey): string
    {
        $key = base64_decode($encodedKey, true);

        if ($key === false) {
            throw InvalidKeyException::invalidBase64();
        }

        $keyLength = strlen($key);
        if ($keyLength !== self::KEY_LENGTH) {
            throw InvalidKeyException::invalidLength($keyLength, self::KEY_LENGTH);
        }

        return $key;
    }
}
