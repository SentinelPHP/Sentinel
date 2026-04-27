<?php

declare(strict_types=1);

namespace SentinelPHP\Encrypt\Exception;

use RuntimeException;

final class EncryptionException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function encryptionFailed(string $reason = ''): self
    {
        $message = 'Encryption failed';
        if ($reason !== '') {
            $message .= ': ' . $reason;
        }

        return new self($message);
    }

    public static function decryptionFailed(string $reason = ''): self
    {
        $message = 'Decryption failed';
        if ($reason !== '') {
            $message .= ': ' . $reason;
        }

        return new self($message);
    }

    public static function invalidCiphertext(): self
    {
        return new self('Invalid ciphertext format');
    }
}
