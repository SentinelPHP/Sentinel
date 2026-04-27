<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class AuthenticationException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?string $tokenId = null,
    ) {
        parent::__construct($message);
    }

    public static function missingToken(): self
    {
        return new self('Missing or invalid Authorization header');
    }

    public static function invalidToken(?string $tokenId = null): self
    {
        return new self('Invalid API token', $tokenId);
    }

    public static function inactiveToken(?string $tokenId = null): self
    {
        return new self('API token is inactive', $tokenId);
    }

    public function getHttpStatusCode(): int
    {
        return 401;
    }
}
