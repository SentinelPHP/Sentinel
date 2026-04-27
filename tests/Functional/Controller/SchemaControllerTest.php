<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\UserFactory;
use App\Tests\Factories\UserTokenAccessFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class SchemaControllerTest extends WebTestCase
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

    public function testIndexReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/schemas');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'API Schemas');
        self::assertSelectorExists('form[method="get"]');
        self::assertSelectorExists('table');
    }

    public function testIndexShowsEmptyState(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/schemas');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No schemas found', $crawler->text());
    }

    public function testIndexShowsSchemasForAccessibleTokens(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/api/users',
            'httpMethod' => 'GET',
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/schemas');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table tbody tr');
        self::assertStringContainsString('/api/users', $crawler->text());
    }

    public function testIndexFiltersBySchemaType(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        ApiSchemaFactory::createOne(['token' => $token, 'schemaType' => SchemaType::Request]);
        ApiSchemaFactory::createOne(['token' => $token, 'schemaType' => SchemaType::Response]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/schemas?schema_type=request');

        self::assertResponseIsSuccessful();
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/dashboard/schemas');

        self::assertResponseRedirects('/login');
    }

    // ==================== SHOW TESTS ====================

    public function testShowReturns200(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/schemas/' . $schema->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Schema Details');
    }

    public function testShowReturns404ForNonExistent(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/schemas/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowDeniesAccessToUnauthorizedToken(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/schemas/' . $schema->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testShowAllowsAdminAccess(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/schemas/' . $schema->getId());

        self::assertResponseIsSuccessful();
    }

    // ==================== VERSIONS TESTS ====================

    public function testVersionsReturns200(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/schemas/' . $schema->getId() . '/versions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Schema Version History');
    }

    public function testVersionsDeniesAccessToUnauthorizedToken(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/schemas/' . $schema->getId() . '/versions');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== PROMOTE TESTS ====================

    public function testPromoteRequiresAdmin(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token, 'isMaster' => false]);

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/dashboard/schemas/' . $schema->getId() . '/promote');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPromoteRequiresCsrfToken(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token, 'isMaster' => false]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/dashboard/schemas/' . $schema->getId() . '/promote');

        self::assertResponseRedirects();
    }

    // ==================== EXPORT TESTS ====================

    public function testExportReturns200(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/schemas/' . $schema->getId() . '/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testExportDeniesAccessToUnauthorizedToken(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/schemas/' . $schema->getId() . '/export');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== IMPORT TESTS ====================

    public function testImportRequiresAdmin(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/schemas/import');

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportReturns200ForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/schemas/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }
}
