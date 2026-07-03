<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ApiToken;
use App\Entity\SchemaDrift;
use App\Entity\User;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\SchemaDriftFactory;
use App\Tests\Factories\UserFactory;
use App\Tests\Factories\UserTokenAccessFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class DriftControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;
    private User $user;
    private User $adminUser;
    private ApiToken $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = UserFactory::createOne();
        $this->adminUser = UserFactory::new()->admin()->create();
        $this->token = ApiTokenFactory::createOne();
    }

    public function testDriftListReturns200(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/drifts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Drift Inspector');
    }

    public function testDriftListShowsEmptyState(): void
    {
        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/drifts');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No drifts found', $crawler->text());
    }

    public function testDriftListShowsDrifts(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $this->token,
            'severity' => DriftSeverity::Critical,
            'driftType' => DriftType::FieldAdded,
            'path' => '$.newField',
        ]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/drifts');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.badge.bg-danger');
        self::assertSelectorTextContains('code', '$.newField');
    }

    public function testDriftListFiltersBySeverity(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $this->token,
            'severity' => DriftSeverity::Critical,
        ]);
        SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $this->token,
            'severity' => DriftSeverity::Info,
        ]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/drifts?severity=critical');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.badge.bg-danger');
    }

    public function testDriftListFiltersByToken(): void
    {
        $token2 = ApiTokenFactory::createOne();
        $schema1 = ApiSchemaFactory::createOne(['token' => $this->token]);
        $schema2 = ApiSchemaFactory::createOne(['token' => $token2]);

        SchemaDriftFactory::createOne([
            'schema' => $schema1,
            'token' => $this->token,
            'path' => '$.field1',
        ]);
        SchemaDriftFactory::createOne([
            'schema' => $schema2,
            'token' => $token2,
            'path' => '$.field2',
        ]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/drifts?token_id=' . $this->token->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('code', '$.field1');
    }

    public function testDriftShowReturns200(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $drift = SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $this->token,
        ]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/drifts/' . $drift->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Drift Details');
    }

    public function testDriftShowDisplaysDiffViewer(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $drift = SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $this->token,
            'expectedValue' => ['type' => 'string'],
            'actualValue' => ['type' => 'integer'],
        ]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/drifts/' . $drift->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.diff-panel');
    }

    public function testDriftShowReturns404ForNonExistent(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/drifts/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDriftShowReturns403ForUnauthorizedUser(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $drift = SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $this->token,
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/drifts/' . $drift->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testDriftShowAllowsUserWithTokenAccess(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $drift = SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $this->token,
        ]);

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $this->token,
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/drifts/' . $drift->getId());

        self::assertResponseIsSuccessful();
    }

    public function testDriftAcceptReturns403ForUnauthorizedUser(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $drift = SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $this->token,
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/dashboard/drifts/' . $drift->getId() . '/accept', [
            '_token' => 'any-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDriftAcceptAllowsUserWithTokenAccess(): void
    {
        $schema = ApiSchemaFactory::createOne([
            'token' => $this->token,
            'jsonSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]);
        $drift = SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $this->token,
            'driftType' => DriftType::FieldAdded,
            'path' => 'newField',
            'actualValue' => ['type' => 'integer'],
        ]);

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $this->token,
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/drifts/' . $drift->getId());

        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/dashboard/drifts/' . $drift->getId() . '/accept', [
            '_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/dashboard/drifts/' . $drift->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Drift accepted');
    }

    public function testDriftAcceptRequiresCsrfToken(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $drift = SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $this->token,
        ]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/dashboard/drifts/' . $drift->getId() . '/accept', [
            '_token' => 'invalid',
        ]);

        self::assertResponseRedirects('/dashboard/drifts/' . $drift->getId());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Invalid CSRF token', $crawler->text());
    }

    public function testDriftAcceptUpdatesDrift(): void
    {
        $schema = ApiSchemaFactory::createOne([
            'token' => $this->token,
            'jsonSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]);
        $drift = SchemaDriftFactory::createOne([
            'schema' => $schema,
            'token' => $this->token,
            'driftType' => DriftType::FieldAdded,
            'path' => 'newField',
            'actualValue' => ['type' => 'integer'],
        ]);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/drifts/' . $drift->getId());

        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/dashboard/drifts/' . $drift->getId() . '/accept', [
            '_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/dashboard/drifts/' . $drift->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Drift accepted');
    }

    public function testDriftListOnlyShowsAccessibleDrifts(): void
    {
        $token2 = ApiTokenFactory::createOne();
        $schema1 = ApiSchemaFactory::createOne(['token' => $this->token]);
        $schema2 = ApiSchemaFactory::createOne(['token' => $token2]);

        SchemaDriftFactory::createOne([
            'schema' => $schema1,
            'token' => $this->token,
            'path' => '$.accessible',
        ]);
        SchemaDriftFactory::createOne([
            'schema' => $schema2,
            'token' => $token2,
            'path' => '$.inaccessible',
        ]);

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $this->token,
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/drifts');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('$.accessible', $crawler->text());
        self::assertStringNotContainsString('$.inaccessible', $crawler->text());
    }

    public function testDriftListShowsSeverityCounts(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        SchemaDriftFactory::createMany(3, [
            'schema' => $schema,
            'token' => $this->token,
            'severity' => DriftSeverity::Critical,
        ]);
        SchemaDriftFactory::createMany(2, [
            'schema' => $schema,
            'token' => $this->token,
            'severity' => DriftSeverity::Warning,
        ]);

        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/dashboard/drifts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.text-danger', '3');
        self::assertSelectorTextContains('.text-warning', '2');
    }

    public function testUnauthenticatedUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/dashboard/drifts');

        self::assertResponseRedirects('/login');
    }
}
