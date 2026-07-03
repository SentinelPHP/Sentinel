<?php

declare(strict_types=1);

namespace SentinelPHP\Encrypt;

use SentinelPHP\Encrypt\Exception\EncryptionException;

interface EncryptorInterface
{
    /**
     * Encrypt plaintext using XSalsa20-Poly1305 authenticated encryption.
     *
     * @param string $plaintext The data to encrypt
     * @return string Base64-encoded ciphertext (nonce prepended)
     * @throws EncryptionException If encryption fails
     */
    public function encrypt(string $plaintext): string;

    /**
     * Decrypt ciphertext that was encrypted with encrypt().
     *
     * @param string $ciphertext Base64-encoded ciphertext (with prepended nonce)
     * @return string The decrypted plaintext
     * @throws EncryptionException If decryption fails or ciphertext is invalid
     */
    public function decrypt(string $ciphertext): string;

    /**
     * Check if encryption is enabled (key is configured).
     */
    public function isEnabled(): bool;
}
