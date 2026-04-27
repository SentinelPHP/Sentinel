<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Security\Voter\SchemaVoter;
use App\Service\AccessControl\AccessControlServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(SchemaVoter::class)]
final class SchemaVoterTest extends TestCase
{
    public function testVoteAbstainsForNonApiSchemaSubject(): void
    {
        $voter = new SchemaVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createUser());

        $result = $voter->vote($token, new \stdClass(), [SchemaVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoteAbstainsForUnsupportedAttribute(): void
    {
        $voter = new SchemaVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createUser());

        $result = $voter->vote($token, $this->createApiSchema(), ['UNSUPPORTED']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testVoteDeniesForNonUserToken(): void
    {
        $voter = new SchemaVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $result = $voter->vote($token, $this->createApiSchema(), [SchemaVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteGrantsViewAccessWhenServiceAllows(): void
    {
        $user = $this->createUser();
        $schema = $this->createApiSchema();

        $accessControlService = $this->createMock(AccessControlServiceInterface::class);
        $accessControlService
            ->expects($this->once())
            ->method('canViewSchema')
            ->with($user, $schema)
            ->willReturn(true);

        $voter = new SchemaVoter($accessControlService);
        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('getUser')->willReturn($user);

        $result = $voter->vote($securityToken, $schema, [SchemaVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDeniesViewAccessWhenServiceDenies(): void
    {
        $user = $this->createUser();
        $schema = $this->createApiSchema();

        $accessControlService = $this->createMock(AccessControlServiceInterface::class);
        $accessControlService
            ->expects($this->once())
            ->method('canViewSchema')
            ->with($user, $schema)
            ->willReturn(false);

        $voter = new SchemaVoter($accessControlService);
        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('getUser')->willReturn($user);

        $result = $voter->vote($securityToken, $schema, [SchemaVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testVoteGrantsEditAccessForAdmin(): void
    {
        $voter = new SchemaVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createAdminUser());

        $result = $voter->vote($token, $this->createApiSchema(), [SchemaVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testVoteDeniesEditAccessForRegularUser(): void
    {
        $voter = new SchemaVoter($this->createStub(AccessControlServiceInterface::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createUser());

        $result = $voter->vote($token, $this->createApiSchema(), [SchemaVoter::EDIT]);

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

    private function createApiSchema(): ApiSchema
    {
        $apiToken = new ApiToken();
        $apiToken->setName('Test Token');
        $apiToken->setTokenHash(hash('sha256', 'test'));

        $schema = new ApiSchema();
        $schema->setToken($apiToken);
        $schema->setTargetHost('api.example.com');
        $schema->setEndpointPath('/users');
        $schema->setHttpMethod('GET');

        return $schema;
    }
}
