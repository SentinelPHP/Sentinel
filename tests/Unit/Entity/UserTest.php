<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $user = new User();

        self::assertInstanceOf(Uuid::class, $user->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
        self::assertNull($user->getLastLoginAt());
    }

    #[Test]
    public function setAndGetEmail(): void
    {
        $user = new User();

        $result = $user->setEmail('test@example.com');

        self::assertSame($user, $result);
        self::assertSame('test@example.com', $user->getEmail());
    }

    #[Test]
    public function getUserIdentifierReturnsEmail(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');

        self::assertSame('test@example.com', $user->getUserIdentifier());
    }

    #[Test]
    public function setAndGetPassword(): void
    {
        $user = new User();

        $result = $user->setPassword('hashed_password');

        self::assertSame($user, $result);
        self::assertSame('hashed_password', $user->getPassword());
    }

    #[Test]
    public function getRolesAlwaysIncludesRoleUser(): void
    {
        $user = new User();

        $roles = $user->getRoles();

        self::assertContains('ROLE_USER', $roles);
    }

    #[Test]
    public function setRolesPreservesRoleUser(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);

        $roles = $user->getRoles();

        self::assertContains('ROLE_USER', $roles);
        self::assertContains('ROLE_ADMIN', $roles);
    }

    #[Test]
    public function setRolesDeduplicatesRoles(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_USER']);

        $roles = $user->getRoles();

        self::assertCount(2, $roles);
        self::assertContains('ROLE_USER', $roles);
        self::assertContains('ROLE_ADMIN', $roles);
    }

    #[Test]
    public function setAndGetLastLoginAt(): void
    {
        $user = new User();
        $loginTime = new \DateTimeImmutable('2024-01-15 10:30:00');

        $result = $user->setLastLoginAt($loginTime);

        self::assertSame($user, $result);
        self::assertSame($loginTime, $user->getLastLoginAt());
    }

    #[Test]
    public function lastLoginAtCanBeSetToNull(): void
    {
        $user = new User();
        $user->setLastLoginAt(new \DateTimeImmutable());
        $user->setLastLoginAt(null);

        self::assertNull($user->getLastLoginAt());
    }

    #[Test]
    public function isAdminReturnsTrueForAdminRole(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);

        self::assertTrue($user->isAdmin());
    }

    #[Test]
    public function isAdminReturnsFalseForRegularUser(): void
    {
        $user = new User();
        $user->setRoles([]);

        self::assertFalse($user->isAdmin());
    }

    #[Test]
    public function eraseCredentialsDoesNotThrow(): void
    {
        $user = new User();

        // Should not throw any exception
        $user->eraseCredentials();

        $this->expectNotToPerformAssertions();
    }
}
