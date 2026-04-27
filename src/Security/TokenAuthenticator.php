<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

final class TokenAuthenticator implements TokenAuthenticatorInterface
{
    private const CACHE_PREFIX = 'api_token_';
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly ApiTokenRepository $tokenRepository,
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {
    }

    public function authenticate(Request $request): TokenAuthenticationResult
    {
        $bearerToken = $this->extractBearerToken($request);

        if ($bearerToken === null) {
            return TokenAuthenticationResult::failed('Missing or invalid Authorization header');
        }

        $tokenHash = $this->hashToken($bearerToken);
        $apiToken = $this->findToken($tokenHash);

        if ($apiToken === null) {
            return TokenAuthenticationResult::failed('Invalid API token');
        }

        if (!$apiToken->isActive()) {
            return TokenAuthenticationResult::failed('API token is inactive');
        }

        return TokenAuthenticationResult::success($apiToken);
    }

    public function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');

        if ($authHeader === null || $authHeader === '') {
            return null;
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        if ($token === '') {
            return null;
        }

        return $token;
    }

    private function findToken(string $tokenHash): ?ApiToken
    {
        if ($this->cache !== null) {
            $cacheKey = self::CACHE_PREFIX . $tokenHash;
            $cacheItem = $this->cache->getItem($cacheKey);

            if ($cacheItem->isHit()) {
                /** @var ApiToken|null $cached */
                $cached = $cacheItem->get();
                return $cached;
            }

            $token = $this->tokenRepository->findActiveByTokenHash($tokenHash);

            if ($token !== null) {
                $cacheItem->set($token);
                $cacheItem->expiresAfter(self::CACHE_TTL);
                $this->cache->save($cacheItem);
            }

            return $token;
        }

        return $this->tokenRepository->findActiveByTokenHash($tokenHash);
    }

    public function invalidateTokenCache(string $tokenHash): void
    {
        if ($this->cache !== null) {
            $this->cache->deleteItem(self::CACHE_PREFIX . $tokenHash);
        }
    }
}
