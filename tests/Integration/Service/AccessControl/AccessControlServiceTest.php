<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\AccessControl;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Service\AccessControl\AccessControlServiceInterface;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\UserFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AccessControlServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private AccessControlServiceInterface $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(AccessControlServiceInterface::class);
    }

    #[Test]
    public function canViewTokenGrantsAccessToAdmin(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $token = ApiTokenFactory::new()->create();

        self::assertTrue($this->service->canViewToken($admin, $token));
    }

    #[Test]
    public function canViewTokenDeniesAccessForRegularUserWithoutAccess(): void
    {
        $user = UserFactory::new()->create();
        $token = ApiTokenFactory::new()->create();

        self::assertFalse($this->service->canViewToken($user, $token));
    }

    #[Test]
    public function canViewTokenGrantsAccessForRegularUserWithAccess(): void
    {
        $user = UserFactory::new()->create();
        $token = ApiTokenFactory::new()->create();

        $this->service->grantAccess($user, $token);

        self::assertTrue($this->service->canViewToken($user, $token));
    }

    #[Test]
    public function canViewSchemaGrantsAccessToAdmin(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $token = ApiTokenFactory::new()->create();
        $schema = ApiSchemaFactory::new()->create(['token' => $token]);

        self::assertTrue($this->service->canViewSchema($admin, $schema));
    }

    #[Test]
    public function canViewSchemaDeniesAccessForRegularUserWithoutTokenAccess(): void
    {
        $user = UserFactory::new()->create();
        $token = ApiTokenFactory::new()->create();
        $schema = ApiSchemaFactory::new()->create(['token' => $token]);

        self::assertFalse($this->service->canViewSchema($user, $schema));
    }

    #[Test]
    public function canViewSchemaGrantsAccessForRegularUserWithTokenAccess(): void
    {
        $user = UserFactory::new()->create();
        $token = ApiTokenFactory::new()->create();
        $schema = ApiSchemaFactory::new()->create(['token' => $token]);

        $this->service->grantAccess($user, $token);

        self::assertTrue($this->service->canViewSchema($user, $schema));
    }

    #[Test]
    public function getAccessibleTokensReturnsAllForAdmin(): void
    {
        $admin = UserFactory::new()->admin()->create();
        ApiTokenFactory::new()->create(['name' => 'Token A']);
        ApiTokenFactory::new()->create(['name' => 'Token B']);

        $tokens = $this->service->getAccessibleTokens($admin);

        self::assertCount(2, $tokens);
    }

    #[Test]
    public function getAccessibleTokensReturnsOnlyAssignedForRegularUser(): void
    {
        $user = UserFactory::new()->create();
        $tokenA = ApiTokenFactory::new()->create(['name' => 'Token A']);
        ApiTokenFactory::new()->create(['name' => 'Token B']);

        $this->service->grantAccess($user, $tokenA);

        $tokens = $this->service->getAccessibleTokens($user);

        self::assertCount(1, $tokens);
        self::assertSame($tokenA->getId()->toRfc4122(), $tokens[0]->getId()->toRfc4122());
    }

    #[Test]
    public function grantAccessCreatesNewAccess(): void
    {
        $user = UserFactory::new()->create();
        $token = ApiTokenFactory::new()->create();

        self::assertFalse($this->service->hasExplicitAccess($user, $token));

        $this->service->grantAccess($user, $token);

        self::assertTrue($this->service->hasExplicitAccess($user, $token));
    }

    #[Test]
    public function grantAccessIsIdempotent(): void
    {
        $user = UserFactory::new()->create();
        $token = ApiTokenFactory::new()->create();

        $this->service->grantAccess($user, $token);
        $this->service->grantAccess($user, $token);

        self::assertTrue($this->service->hasExplicitAccess($user, $token));
    }

    #[Test]
    public function revokeAccessRemovesAccess(): void
    {
        $user = UserFactory::new()->create();
        $token = ApiTokenFactory::new()->create();

        $this->service->grantAccess($user, $token);
        self::assertTrue($this->service->hasExplicitAccess($user, $token));

        $result = $this->service->revokeAccess($user, $token);

        self::assertTrue($result);
        self::assertFalse($this->service->hasExplicitAccess($user, $token));
    }

    #[Test]
    public function revokeAccessReturnsFalseIfNotExists(): void
    {
        $user = UserFactory::new()->create();
        $token = ApiTokenFactory::new()->create();

        $result = $this->service->revokeAccess($user, $token);

        self::assertFalse($result);
    }
}
