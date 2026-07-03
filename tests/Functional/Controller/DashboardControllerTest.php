<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Factories\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class DashboardControllerTest extends WebTestCase
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

    public function testDashboardIndexReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar');
        self::assertSelectorExists('.main-content');
        self::assertSelectorTextContains('.header-title', 'Dashboard Overview');
    }

    public function testDashboardServicesReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/services');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Service Health');
    }

    public function testDashboardDriftsReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/drifts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Drift Inspector');
    }

    public function testDashboardLogsRedirectsToRequestLogController(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/logs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Request Logs');
        self::assertSelectorExists('table');
        self::assertSelectorExists('form[method="get"]');
    }

    public function testDashboardTokensRedirectsToTokenController(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/tokens');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'API Tokens');
        self::assertSelectorExists('table');
    }

    public function testDashboardSchemasRedirectsToSchemaController(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/schemas');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'API Schemas');
        self::assertSelectorExists('table');
        self::assertSelectorExists('form[method="get"]');
    }

    public function testDashboardAlertsRedirectsToAlertConfigController(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/alerts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Alert Configurations');
        self::assertSelectorExists('table');
        self::assertSelectorExists('form[method="get"]');
    }

    public function testDashboardUsersRequiresAdmin(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDashboardUsersReturns200ForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'User Management');
    }

    public function testDashboardSettingsReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Settings');
    }

    public function testDashboardLayoutHasSidebarNavigation(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar .nav-link[href="/dashboard"]');
        self::assertSelectorExists('.sidebar .nav-link[href="/dashboard/services"]');
        self::assertSelectorExists('.sidebar .nav-link[href="/dashboard/drifts"]');
        self::assertSelectorExists('.sidebar .nav-link[href="/dashboard/logs"]');
        self::assertSelectorExists('.sidebar .nav-link[href="/dashboard/tokens"]');
        self::assertSelectorExists('.sidebar .nav-link[href="/dashboard/schemas"]');
        self::assertSelectorExists('.sidebar .nav-link[href="/dashboard/alerts"]');
        self::assertSelectorExists('.sidebar .nav-link[href="/dashboard/settings"]');
    }

    public function testDashboardLayoutIncludesEncoreAssets(): void
    {
        $entrypointsFile = self::getContainer()->getParameter('kernel.project_dir') . '/public/build/entrypoints.json';
        if (!file_exists($entrypointsFile)) {
            $this->markTestSkipped('Webpack Encore assets not built');
        }

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[href*="/build/"]');
        self::assertSelectorExists('script[src*="/build/"]');
    }

    public function testUnauthenticatedUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }
}
