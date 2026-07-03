<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\UserFactory;
use App\Tests\Factories\UserTokenAccessFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class UserControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;
    private User $user;
    private User $adminUser;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = UserFactory::createOne();
        $this->adminUser = UserFactory::new()->admin()->create();
    }

    // ==================== INDEX TESTS ====================

    public function testIndexRequiresAdmin(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexReturns200ForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'User Management');
        self::assertSelectorExists('table');
    }

    public function testIndexShowsUsers(): void
    {
        UserFactory::createOne(['email' => 'testuser@example.com']);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/users');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('testuser@example.com', $crawler->text());
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/dashboard/users');

        self::assertResponseRedirects('/login');
    }

    // ==================== SHOW TESTS ====================

    public function testShowRequiresAdmin(): void
    {
        $targetUser = UserFactory::createOne();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/users/' . $targetUser->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testShowReturns200ForAdmin(): void
    {
        $targetUser = UserFactory::createOne(['email' => 'viewuser@example.com']);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/users/' . $targetUser->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'User Details');
        self::assertStringContainsString('viewuser@example.com', $crawler->text());
    }

    public function testShowDisplaysTokenAccess(): void
    {
        $targetUser = UserFactory::createOne();
        $token = ApiTokenFactory::createOne(['name' => 'Test API Token']);
        UserTokenAccessFactory::createOne(['user' => $targetUser, 'token' => $token]);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/users/' . $targetUser->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Test API Token', $crawler->text());
    }

    // ==================== PERMISSIONS TESTS ====================

    public function testPermissionsRequiresAdmin(): void
    {
        $targetUser = UserFactory::createOne();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/users/' . $targetUser->getId() . '/permissions');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPermissionsReturns200ForAdmin(): void
    {
        $targetUser = UserFactory::createOne();

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/users/' . $targetUser->getId() . '/permissions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Manage Permissions');
        self::assertSelectorExists('form');
    }

    public function testPermissionsRedirectsForAdminUser(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/users/' . $this->adminUser->getId() . '/permissions');

        self::assertResponseRedirects('/dashboard/users/' . $this->adminUser->getId());
    }

    public function testPermissionsShowsAllTokens(): void
    {
        $targetUser = UserFactory::createOne();
        ApiTokenFactory::createOne(['name' => 'Available Token']);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/users/' . $targetUser->getId() . '/permissions');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Available Token', $crawler->text());
    }

    public function testPermissionsPostUpdatesAccess(): void
    {
        $targetUser = UserFactory::createOne();
        $token = ApiTokenFactory::createOne(['name' => 'Grant Token']);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/users/' . $targetUser->getId() . '/permissions');

        $this->client->submitForm('Save', [
            'tokens' => [$token->getId()->toRfc4122()],
        ]);

        self::assertResponseRedirects('/dashboard/users/' . $targetUser->getId());
    }
}
