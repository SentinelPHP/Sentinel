<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Enum\AlertChannelType;
use SentinelPHP\Drift\Enum\DriftSeverity;
use App\Tests\Factories\AlertConfigurationFactory;
use App\Tests\Factories\AlertLogFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AlertConfigControllerTest extends WebTestCase
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
        $this->client->request('GET', '/dashboard/alerts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Alert Configurations');
        self::assertSelectorExists('form[method="get"]');
        self::assertSelectorExists('table');
    }

    public function testIndexShowsEmptyState(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/alerts');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No alert configurations found', $crawler->text());
    }

    public function testIndexShowsAlerts(): void
    {
        AlertConfigurationFactory::createOne([
            'channelType' => AlertChannelType::Slack,
            'channelConfig' => ['webhook_url' => 'https://hooks.slack.com/test'],
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/alerts');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table tbody tr');
        self::assertStringContainsString('Slack', $crawler->text());
    }

    public function testIndexFiltersByChannelType(): void
    {
        AlertConfigurationFactory::createOne(['channelType' => AlertChannelType::Slack]);
        AlertConfigurationFactory::createOne(['channelType' => AlertChannelType::Webhook]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/alerts?channelType=slack');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table tbody tr');
    }

    public function testIndexFiltersByStatus(): void
    {
        AlertConfigurationFactory::createOne(['isActive' => true]);
        AlertConfigurationFactory::createOne(['isActive' => false]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/alerts?status=active');

        self::assertResponseIsSuccessful();
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/dashboard/alerts');

        self::assertResponseRedirects('/login');
    }

    // ==================== NEW TESTS ====================

    public function testNewRequiresAdmin(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/alerts/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewReturns200ForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/alerts/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    // ==================== EDIT TESTS ====================

    public function testEditRequiresAdmin(): void
    {
        $alert = AlertConfigurationFactory::createOne();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/alerts/' . $alert->getId() . '/edit');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditReturns200ForAdmin(): void
    {
        $alert = AlertConfigurationFactory::createOne();

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/alerts/' . $alert->getId() . '/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEditReturns404ForNonExistent(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/dashboard/alerts/00000000-0000-0000-0000-000000000000/edit');

        self::assertResponseStatusCodeSame(404);
    }

    // ==================== TOGGLE TESTS ====================

    public function testToggleRequiresAdmin(): void
    {
        $alert = AlertConfigurationFactory::createOne();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/dashboard/alerts/' . $alert->getId() . '/toggle');

        self::assertResponseStatusCodeSame(403);
    }

    public function testToggleRequiresCsrfToken(): void
    {
        $alert = AlertConfigurationFactory::createOne(['isActive' => true]);

        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/dashboard/alerts/' . $alert->getId() . '/toggle');

        self::assertResponseRedirects('/dashboard/alerts');
    }

    // ==================== DELETE TESTS ====================

    public function testDeleteRequiresAdmin(): void
    {
        $alert = AlertConfigurationFactory::createOne();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/dashboard/alerts/' . $alert->getId() . '/delete');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== MUTE/UNMUTE TESTS ====================

    public function testMuteRequiresAdmin(): void
    {
        $alert = AlertConfigurationFactory::createOne();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/dashboard/alerts/' . $alert->getId() . '/mute');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnmuteRequiresAdmin(): void
    {
        $alert = AlertConfigurationFactory::new()->muted()->create();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/dashboard/alerts/' . $alert->getId() . '/unmute');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== TEST ALERT TESTS ====================

    public function testTestAlertRequiresAdmin(): void
    {
        $alert = AlertConfigurationFactory::createOne();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/dashboard/alerts/' . $alert->getId() . '/test');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== HISTORY TESTS ====================

    public function testHistoryReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/alerts/history');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Alert History');
    }

    public function testHistoryShowsLogs(): void
    {
        $alert = AlertConfigurationFactory::createOne();
        AlertLogFactory::createOne(['alertConfiguration' => $alert]);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/alerts/history');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table tbody tr');
    }

    public function testHistoryFiltersByChannelType(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/alerts/history?channelType=slack');

        self::assertResponseIsSuccessful();
    }

    public function testHistoryFiltersByStatus(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/alerts/history?status=sent');

        self::assertResponseIsSuccessful();
    }
}
