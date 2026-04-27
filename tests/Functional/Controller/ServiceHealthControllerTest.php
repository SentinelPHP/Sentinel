<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\RequestLogFactory;
use App\Tests\Factories\UserFactory;
use App\Tests\Factories\UserTokenAccessFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ServiceHealthControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = UserFactory::createOne();
    }

    // ==================== INDEX TESTS ====================

    public function testIndexReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/services');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Service Health');
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/dashboard/services');

        self::assertResponseRedirects('/login');
    }

    // ==================== SHOW TESTS ====================

    public function testShowReturns200WhenDataExists(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        RequestLogFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/services/api.example.com');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Service Details');
    }

    public function testShowReturns404WhenNoData(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/services/nonexistent.example.com');

        self::assertResponseStatusCodeSame(404);
    }

    // ==================== LATENCY TESTS ====================

    /**
     * Note: This test verifies the route exists and returns 404 when no recent data.
     * Full integration testing would require time-based fixtures.
     */
    public function testLatencyReturns404WhenNoRecentData(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        RequestLogFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'latencyMs' => 150,
        ]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/services/api.example.com/latency');

        // Service health requires recent data within time window
        self::assertResponseStatusCodeSame(404);
    }

    public function testLatencyReturns404WhenNoData(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/services/nonexistent.example.com/latency');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLatencyRouteRequiresAuthentication(): void
    {
        $this->client->request('GET', '/dashboard/services/api.example.com/latency');

        self::assertResponseRedirects('/login');
    }
}
