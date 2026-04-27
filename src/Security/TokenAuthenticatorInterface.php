<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

interface TokenAuthenticatorInterface
{
    public function authenticate(Request $request): TokenAuthenticationResult;

    public function hashToken(string $plainToken): string;

    public function invalidateTokenCache(string $tokenHash): void;
}
