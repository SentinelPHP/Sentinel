<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Exception\AuthenticationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthenticationException::class)]
final class AuthenticationExceptionTest extends TestCase
{
    #[Test]
    public function missingTokenCreatesExceptionWithCorrectMessage(): void
    {
        $exception = AuthenticationException::missingToken();

        $this->assertSame('Missing or invalid Authorization header', $exception->getMessage());
        $this->assertNull($exception->tokenId);
        $this->assertSame(401, $exception->getHttpStatusCode());
    }

    #[Test]
    public function invalidTokenCreatesExceptionWithTokenId(): void
    {
        $tokenId = 'abc-123-def';
        $exception = AuthenticationException::invalidToken($tokenId);

        $this->assertSame('Invalid API token', $exception->getMessage());
        $this->assertSame($tokenId, $exception->tokenId);
        $this->assertSame(401, $exception->getHttpStatusCode());
    }

    #[Test]
    public function invalidTokenWorksWithoutTokenId(): void
    {
        $exception = AuthenticationException::invalidToken();

        $this->assertSame('Invalid API token', $exception->getMessage());
        $this->assertNull($exception->tokenId);
    }

    #[Test]
    public function inactiveTokenCreatesExceptionWithTokenId(): void
    {
        $tokenId = 'xyz-789';
        $exception = AuthenticationException::inactiveToken($tokenId);

        $this->assertSame('API token is inactive', $exception->getMessage());
        $this->assertSame($tokenId, $exception->tokenId);
        $this->assertSame(401, $exception->getHttpStatusCode());
    }
}
