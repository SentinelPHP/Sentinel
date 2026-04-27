<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use App\Security\TokenAuthenticator;
use App\Security\TokenAuthenticationResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(TokenAuthenticator::class)]
#[CoversClass(TokenAuthenticationResult::class)]
#[AllowMockObjectsWithoutExpectations]
final class TokenAuthenticatorTest extends TestCase
{
    private ApiTokenRepository&MockObject $tokenRepository;
    private CacheItemPoolInterface&MockObject $cache;
    private TokenAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->tokenRepository = $this->createMock(ApiTokenRepository::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->authenticator = new TokenAuthenticator($this->tokenRepository, $this->cache);
    }

    #[Test]
    public function authenticateFailsWhenAuthorizationHeaderMissing(): void
    {
        $request = Request::create('/api/test', 'GET');

        $result = $this->authenticator->authenticate($request);

        self::assertFalse($result->isAuthenticated);
        self::assertNull($result->token);
        self::assertStringContainsString('Authorization', $result->error ?? '');
    }

    #[Test]
    public function authenticateFailsWhenAuthorizationHeaderEmpty(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', '');

        $result = $this->authenticator->authenticate($request);

        self::assertFalse($result->isAuthenticated);
    }

    #[Test]
    public function authenticateFailsWhenNotBearerToken(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');

        $result = $this->authenticator->authenticate($request);

        self::assertFalse($result->isAuthenticated);
        self::assertStringContainsString('Authorization', $result->error ?? '');
    }

    #[Test]
    public function authenticateFailsWhenBearerTokenEmpty(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ');

        $result = $this->authenticator->authenticate($request);

        self::assertFalse($result->isAuthenticated);
    }

    #[Test]
    public function authenticateFailsWhenTokenNotFound(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer invalid-token');

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->tokenRepository->method('findActiveByTokenHash')->willReturn(null);

        $result = $this->authenticator->authenticate($request);

        self::assertFalse($result->isAuthenticated);
        self::assertStringContainsString('Invalid', $result->error ?? '');
    }

    #[Test]
    public function authenticateFailsWhenTokenInactive(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer valid-token');

        $apiToken = $this->createMock(ApiToken::class);
        $apiToken->method('isActive')->willReturn(false);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($apiToken);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $result = $this->authenticator->authenticate($request);

        self::assertFalse($result->isAuthenticated);
        self::assertStringContainsString('inactive', $result->error ?? '');
    }

    #[Test]
    public function authenticateSucceedsWithValidToken(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer valid-token');

        $apiToken = $this->createMock(ApiToken::class);
        $apiToken->method('isActive')->willReturn(true);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->expects(self::once())->method('save');
        $this->tokenRepository->method('findActiveByTokenHash')->willReturn($apiToken);

        $result = $this->authenticator->authenticate($request);

        self::assertTrue($result->isAuthenticated);
        self::assertSame($apiToken, $result->token);
        self::assertNull($result->error);
    }

    #[Test]
    public function authenticateUsesCachedToken(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer cached-token');

        $apiToken = $this->createMock(ApiToken::class);
        $apiToken->method('isActive')->willReturn(true);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($apiToken);

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->tokenRepository->expects(self::never())->method('findActiveByTokenHash');

        $result = $this->authenticator->authenticate($request);

        self::assertTrue($result->isAuthenticated);
        self::assertSame($apiToken, $result->token);
    }

    #[Test]
    public function authenticateWorksWithoutCache(): void
    {
        $authenticatorNoCache = new TokenAuthenticator($this->tokenRepository, null);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer valid-token');

        $apiToken = $this->createMock(ApiToken::class);
        $apiToken->method('isActive')->willReturn(true);

        $this->tokenRepository->method('findActiveByTokenHash')->willReturn($apiToken);

        $result = $authenticatorNoCache->authenticate($request);

        self::assertTrue($result->isAuthenticated);
        self::assertSame($apiToken, $result->token);
    }

    #[Test]
    public function hashTokenReturnsSha256Hash(): void
    {
        $plainToken = 'my-secret-token';
        $expectedHash = hash('sha256', $plainToken);

        $actualHash = $this->authenticator->hashToken($plainToken);

        self::assertSame($expectedHash, $actualHash);
    }

    #[Test]
    public function invalidateTokenCacheDeletesCacheItem(): void
    {
        $tokenHash = 'abc123hash';

        $this->cache->expects(self::once())
            ->method('deleteItem')
            ->with('api_token_' . $tokenHash);

        $this->authenticator->invalidateTokenCache($tokenHash);
    }

    #[Test]
    public function invalidateTokenCacheDoesNothingWithoutCache(): void
    {
        $authenticatorNoCache = new TokenAuthenticator($this->tokenRepository, null);

        $authenticatorNoCache->invalidateTokenCache('any-hash');

        // No exception thrown means success - cache operations are skipped when cache is null
        $this->addToAssertionCount(1);
    }
}
