<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiToken;

final readonly class TokenAuthenticationResult
{
    private function __construct(
        public bool $isAuthenticated,
        public ?ApiToken $token = null,
        public ?string $error = null,
    ) {
    }

    public static function success(ApiToken $token): self
    {
        return new self(true, $token);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
