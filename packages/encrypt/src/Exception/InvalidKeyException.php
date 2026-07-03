<?php

declare(strict_types=1);

namespace SentinelPHP\Encrypt\Exception;

use InvalidArgumentException;

final class InvalidKeyException extends InvalidArgumentException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function missingKey(): self
    {
        return new self('Encryption key not configured');
    }

    public static function invalidLength(int $actual, int $expected): self
    {
        return new self(sprintf(
            'Invalid encryption key length: expected %d bytes, got %d bytes',
            $expected,
            $actual
        ));
    }

    public static function invalidBase64(): self
    {
        return new self('Encryption key is not valid base64');
    }
}
