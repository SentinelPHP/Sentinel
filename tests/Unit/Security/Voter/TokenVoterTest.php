<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Security\Voter\TokenVoter;
use App\Service\AccessControl\AccessControlServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(TokenVoter::class)]
final class TokenVoterTest extends TestCase
{
    public function testVoteAbstainsForNonApiTokenSubject(): void
    {
        $voter = new TokenVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createUser());

        $result = $voter->vote($token, new \stdClass(), [TokenVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoteAbstainsForUnsupportedAttribute(): void
    {
        $voter = new TokenVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createUser());

        $result = $voter->vote($token, $this->createApiToken(), ['UNSUPPORTED']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoteDeniesForNonUserToken(): void
    {
        $voter = new TokenVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $result = $voter->vote($token, $this->createApiToken(), [TokenVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteGrantsViewAccessWhenServiceAllows(): void
    {
        $user = $this->createUser();
        $apiToken = $this->createApiToken();

        $accessControlService = $this->createMock(AccessControlServiceInterface::class);
        $accessControlService
            ->expects($this->once())
            ->method('canViewToken')
            ->with($user, $apiToken)
            ->willReturn(true);

        $voter = new TokenVoter($accessControlService);
        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('getUser')->willReturn($user);

        $result = $voter->vote($securityToken, $apiToken, [TokenVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDeniesViewAccessWhenServiceDenies(): void
    {
        $user = $this->createUser();
        $apiToken = $this->createApiToken();

        $accessControlService = $this->createMock(AccessControlServiceInterface::class);
        $accessControlService
            ->expects($this->once())
            ->method('canViewToken')
            ->with($user, $apiToken)
            ->willReturn(false);

        $voter = new TokenVoter($accessControlService);
        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('getUser')->willReturn($user);

        $result = $voter->vote($securityToken, $apiToken, [TokenVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteGrantsEditAccessForAdmin(): void
    {
        $voter = new TokenVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createAdminUser());

        $result = $voter->vote($token, $this->createApiToken(), [TokenVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDeniesEditAccessForRegularUser(): void
    {
        $voter = new TokenVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createUser());

        $result = $voter->vote($token, $this->createApiToken(), [TokenVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteGrantsDeleteAccessForAdmin(): void
    {
        $voter = new TokenVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createAdminUser());

        $result = $voter->vote($token, $this->createApiToken(), [TokenVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDeniesDeleteAccessForRegularUser(): void
    {
        $voter = new TokenVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createUser());

        $result = $voter->vote($token, $this->createApiToken(), [TokenVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setEmail('user@example.com');
        $user->setPassword('hashed');
        $user->setRoles([]);

        return $user;
    }

    private function createAdminUser(): User
    {
        $user = new User();
        $user->setEmail('admin@example.com');
        $user->setPassword('hashed');
        $user->setRoles(['ROLE_ADMIN']);

        return $user;
    }

    private function createApiToken(): ApiToken
    {
        $token = new ApiToken();
        $token->setName('Test Token');
        $token->setTokenHash(hash('sha256', 'test'));

        return $token;
    }
}
