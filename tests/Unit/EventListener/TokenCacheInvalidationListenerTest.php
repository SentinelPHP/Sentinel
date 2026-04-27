<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\ApiToken;
use App\EventListener\TokenCacheInvalidationListener;
use App\Security\TokenAuthenticatorInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenCacheInvalidationListener::class)]
#[AllowMockObjectsWithoutExpectations]
final class TokenCacheInvalidationListenerTest extends TestCase
{
    private TokenAuthenticatorInterface&MockObject $tokenAuthenticator;
    private TokenCacheInvalidationListener $listener;

    protected function setUp(): void
    {
        $this->tokenAuthenticator = $this->createMock(TokenAuthenticatorInterface::class);
        $this->listener = new TokenCacheInvalidationListener($this->tokenAuthenticator);
    }

    #[Test]
    public function postUpdateInvalidatesCache(): void
    {
        $token = $this->createMock(ApiToken::class);
        $token->method('getTokenHash')->willReturn('abc123hash');

        $this->tokenAuthenticator->expects(self::once())
            ->method('invalidateTokenCache')
            ->with('abc123hash');

        $this->listener->postUpdate($token);
    }

    #[Test]
    public function postRemoveInvalidatesCache(): void
    {
        $token = $this->createMock(ApiToken::class);
        $token->method('getTokenHash')->willReturn('def456hash');

        $this->tokenAuthenticator->expects(self::once())
            ->method('invalidateTokenCache')
            ->with('def456hash');

        $this->listener->postRemove($token);
    }
}
