<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\ApiToken;
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

class DashboardEventsControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;
    private User $user;
    private User $adminUser;
    private ApiToken $token;

    /**
     * @return list<array<string, mixed>>
     */
    private function getJsonResponse(): array
    {
        $data = json_decode($this->client->getResponse()->getContent() ?: '[]', true);
        self::assertIsArray($data);

        /** @var list<array<string, mixed>> */
        return array_values($data);
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = UserFactory::createOne();
        $this->adminUser = UserFactory::new()->admin()->create();
        $this->token = ApiTokenFactory::createOne();
    }

    // ==================== AUTHENTICATION TESTS ====================

    public function testRecentEventsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/dashboard/events/recent');

        self::assertResponseRedirects('/login');
    }

    public function testRecentEventsReturns200ForAuthenticatedUser(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/api/dashboard/events/recent');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    // ==================== AUTHORIZATION TESTS ====================

    public function testRecentEventsReturnsEmptyArrayForUserWithNoTokenAccess(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-10 seconds'))
            ->create([
                'schema' => $schema,
                'token' => $this->token,
            ]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/api/dashboard/events/recent');

        self::assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent() ?: '[]', true);
        self::assertSame([], $data);
    }

    public function testRecentEventsReturnsEventsForUserWithTokenAccess(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $drift = SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-10 seconds'))
            ->create([
                'schema' => $schema,
                'token' => $this->token,
                'severity' => DriftSeverity::Warning,
                'driftType' => DriftType::FieldAdded,
                'path' => '$.newField',
            ]);

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $this->token,
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/api/dashboard/events/recent?since=' . urlencode((new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::ATOM)));

        self::assertResponseIsSuccessful();

        $data = $this->getJsonResponse();
        self::assertCount(1, $data);
        self::assertSame('drift_detected', $data[0]['type']);
        self::assertSame($drift->getId()->toRfc4122(), $data[0]['id']);
        self::assertSame('warning', $data[0]['severity']);
        self::assertSame('$.newField', $data[0]['path']);
    }

    public function testRecentEventsReturnsAllEventsForAdmin(): void
    {
        $token2 = ApiTokenFactory::createOne();
        $schema1 = ApiSchemaFactory::createOne(['token' => $this->token]);
        $schema2 = ApiSchemaFactory::createOne(['token' => $token2]);

        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-10 seconds'))
            ->create([
                'schema' => $schema1,
                'token' => $this->token,
                'path' => '$.field1',
            ]);
        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-5 seconds'))
            ->create([
                'schema' => $schema2,
                'token' => $token2,
                'path' => '$.field2',
            ]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/api/dashboard/events/recent?since=' . urlencode((new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::ATOM)));

        self::assertResponseIsSuccessful();

        $data = $this->getJsonResponse();
        self::assertCount(2, $data);

        $paths = array_column($data, 'path');
        self::assertContains('$.field1', $paths);
        self::assertContains('$.field2', $paths);
    }

    public function testRecentEventsOnlyReturnsAccessibleTokenEvents(): void
    {
        $token2 = ApiTokenFactory::createOne();
        $schema1 = ApiSchemaFactory::createOne(['token' => $this->token]);
        $schema2 = ApiSchemaFactory::createOne(['token' => $token2]);

        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-10 seconds'))
            ->create([
                'schema' => $schema1,
                'token' => $this->token,
                'path' => '$.accessible',
            ]);
        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-5 seconds'))
            ->create([
                'schema' => $schema2,
                'token' => $token2,
                'path' => '$.inaccessible',
            ]);

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $this->token,
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/api/dashboard/events/recent?since=' . urlencode((new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::ATOM)));

        self::assertResponseIsSuccessful();

        $data = $this->getJsonResponse();
        self::assertCount(1, $data);
        self::assertSame('$.accessible', $data[0]['path']);
    }

    // ==================== RESPONSE FORMAT TESTS ====================

    public function testRecentEventsReturnsCorrectStructure(): void
    {
        $schema = ApiSchemaFactory::createOne([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
        ]);
        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-10 seconds'))
            ->create([
                'schema' => $schema,
                'token' => $this->token,
                'severity' => DriftSeverity::Critical,
                'driftType' => DriftType::TypeChanged,
                'path' => '$.data.id',
            ]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/api/dashboard/events/recent?since=' . urlencode((new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::ATOM)));

        self::assertResponseIsSuccessful();

        $data = $this->getJsonResponse();
        self::assertCount(1, $data);

        $event = $data[0];
        self::assertArrayHasKey('type', $event);
        self::assertArrayHasKey('id', $event);
        self::assertArrayHasKey('severity', $event);
        self::assertArrayHasKey('driftType', $event);
        self::assertArrayHasKey('path', $event);
        self::assertArrayHasKey('endpoint', $event);
        self::assertArrayHasKey('method', $event);
        self::assertArrayHasKey('host', $event);
        self::assertArrayHasKey('tokenId', $event);
        self::assertArrayHasKey('tokenName', $event);
        self::assertArrayHasKey('createdAt', $event);

        self::assertSame('drift_detected', $event['type']);
        self::assertSame('critical', $event['severity']);
        self::assertSame('type_changed', $event['driftType']);
        self::assertSame('$.data.id', $event['path']);
        self::assertSame('/users', $event['endpoint']);
        self::assertSame('GET', $event['method']);
        self::assertSame('api.example.com', $event['host']);
    }

    public function testRecentEventsDefaultsSinceToLast30Seconds(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);

        // Create a drift from 10 seconds ago (should be included)
        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-10 seconds'))
            ->create([
                'schema' => $schema,
                'token' => $this->token,
                'path' => '$.recent',
            ]);

        // Create a drift from 2 minutes ago (should NOT be included with default since)
        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-2 minutes'))
            ->create([
                'schema' => $schema,
                'token' => $this->token,
                'path' => '$.old',
            ]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/api/dashboard/events/recent');

        self::assertResponseIsSuccessful();

        $data = $this->getJsonResponse();
        self::assertCount(1, $data);
        self::assertSame('$.recent', $data[0]['path']);
    }

    public function testRecentEventsRespectsSinceParameter(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);

        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-1 minute'))
            ->create([
                'schema' => $schema,
                'token' => $this->token,
                'path' => '$.recent',
            ]);

        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-10 minutes'))
            ->create([
                'schema' => $schema,
                'token' => $this->token,
                'path' => '$.older',
            ]);

        $this->client->loginUser($this->adminUser);
        $since = (new \DateTimeImmutable('-5 minutes'))->format(\DateTimeInterface::ATOM);
        $this->client->request('GET', '/api/dashboard/events/recent?since=' . urlencode($since));

        self::assertResponseIsSuccessful();

        $data = $this->getJsonResponse();
        self::assertCount(1, $data);
        self::assertSame('$.recent', $data[0]['path']);
    }

    public function testRecentEventsReturnsSortedByCreatedAtDescending(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);

        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-30 seconds'))
            ->create([
                'schema' => $schema,
                'token' => $this->token,
                'path' => '$.first',
            ]);
        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-20 seconds'))
            ->create([
                'schema' => $schema,
                'token' => $this->token,
                'path' => '$.second',
            ]);
        SchemaDriftFactory::new()
            ->createdAt(new \DateTimeImmutable('-10 seconds'))
            ->create([
                'schema' => $schema,
                'token' => $this->token,
                'path' => '$.third',
            ]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/api/dashboard/events/recent?since=' . urlencode((new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::ATOM)));

        self::assertResponseIsSuccessful();

        $data = $this->getJsonResponse();
        self::assertCount(3, $data);

        // Most recent first
        self::assertSame('$.third', $data[0]['path']);
        self::assertSame('$.second', $data[1]['path']);
        self::assertSame('$.first', $data[2]['path']);
    }
}
