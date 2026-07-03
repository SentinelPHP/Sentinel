<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ApiToken;
use App\Security\TokenAuthenticatorInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: ApiToken::class)]
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: ApiToken::class)]
final class TokenCacheInvalidationListener
{
    public function __construct(
        private readonly TokenAuthenticatorInterface $tokenAuthenticator,
    ) {
    }

    public function postUpdate(ApiToken $token): void
    {
        $this->invalidateCache($token);
    }

    public function postRemove(ApiToken $token): void
    {
        $this->invalidateCache($token);
    }

    private function invalidateCache(ApiToken $token): void
    {
        $this->tokenAuthenticator->invalidateTokenCache($token->getTokenHash());
    }
}
