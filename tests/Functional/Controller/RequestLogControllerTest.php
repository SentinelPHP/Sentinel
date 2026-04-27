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

class RequestLogControllerTest extends WebTestCase
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
        $this->client->request('GET', '/dashboard/logs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Request Logs');
        self::assertSelectorExists('form[method="get"]');
        self::assertSelectorExists('table');
    }

    public function testIndexShowsEmptyState(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/logs');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No request logs found', $crawler->text());
    }

    public function testIndexShowsLogsForAccessibleTokens(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        RequestLogFactory::createOne([
            'token' => $token,
            'requestMethod' => 'GET',
            'requestPath' => '/api/users',
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/logs');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table tbody tr');
        self::assertStringContainsString('/api/users', $crawler->text());
    }

    public function testIndexFiltersLogs(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        RequestLogFactory::createOne(['token' => $token, 'requestMethod' => 'GET']);
        RequestLogFactory::createOne(['token' => $token, 'requestMethod' => 'POST']);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/logs?method=GET');

        self::assertResponseIsSuccessful();
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/dashboard/logs');

        self::assertResponseRedirects('/login');
    }

    // ==================== SHOW TESTS ====================

    public function testShowReturns200(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        $log = RequestLogFactory::createOne(['token' => $token]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/logs/' . $log->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Request Log Detail');
    }

    public function testShowReturns404ForNonExistent(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/logs/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowDeniesAccessToUnauthorizedToken(): void
    {
        $token = ApiTokenFactory::createOne();
        $log = RequestLogFactory::createOne(['token' => $token]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/logs/' . $log->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testShowAllowsAdminAccess(): void
    {
        $token = ApiTokenFactory::createOne();
        $log = RequestLogFactory::createOne(['token' => $token]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/logs/' . $log->getId());

        self::assertResponseIsSuccessful();
    }

    // ==================== EXPORT TESTS ====================

    public function testExportCsvReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/logs/export.csv');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    public function testExportJsonReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/logs/export.json');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testExportCsvContainsData(): void
    {
        $token = ApiTokenFactory::createOne();
        UserTokenAccessFactory::createOne(['user' => $this->user, 'token' => $token]);
        RequestLogFactory::createOne([
            'token' => $token,
            'requestPath' => '/api/export-test',
        ]);

        $this->client->loginUser($this->user);
        ob_start();
        $this->client->request('GET', '/dashboard/logs/export.csv');
        ob_end_clean();

        self::assertResponseIsSuccessful();
    }

    public function testExportRequiresAuthentication(): void
    {
        $this->client->request('GET', '/dashboard/logs/export.csv');

        self::assertResponseRedirects('/login');
    }
}
