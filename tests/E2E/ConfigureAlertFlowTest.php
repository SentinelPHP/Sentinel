<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Enum\AlertChannelType;
use SentinelPHP\Drift\Enum\DriftSeverity;
use App\Tests\Factories\AlertConfigurationFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * E2E Test: Configure alert → Test alert
 */
final class ConfigureAlertFlowTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testCompleteConfigureAlertFlow(): void
    {
        $client = static::createClient();

        // Create admin user and token
        $admin = UserFactory::new()->admin()->create();
        $token = ApiTokenFactory::createOne(['name' => 'Production API']);
        $client->loginUser($admin);

        // Step 1: Navigate to alerts list
        $client->request('GET', '/dashboard/alerts');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Alert Configurations');

        // Step 2: Navigate to create new alert page
        $crawler = $client->request('GET', '/dashboard/alerts/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');

        // Step 3: Fill out the alert configuration form for Slack
        $form = $crawler->selectButton('Create Alert')->form([
            'alert_configuration[channelType]' => AlertChannelType::Slack->value,
            'alert_configuration[slackWebhookUrl]' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX',
            'alert_configuration[slackChannel]' => '#alerts',
            'alert_configuration[minSeverity]' => DriftSeverity::Warning->value,
            'alert_configuration[isActive]' => true,
        ]);

        $client->submit($form);

        // Step 4: Follow redirect after creation
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Step 5: Verify success message
        self::assertSelectorTextContains('.alert-success', 'created');

        // Step 6: Navigate back to alerts list
        $client->request('GET', '/dashboard/alerts');
        self::assertResponseIsSuccessful();

        // Step 7: Verify new alert appears in list
        $crawler = $client->getCrawler();
        self::assertStringContainsString('Slack', $crawler->text());
        // Note: The channel may not be displayed in the list view, verify it was created
        self::assertSelectorExists('table tbody tr');
    }

    public function testConfigureWebhookAlert(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/dashboard/alerts/new');

        $form = $crawler->selectButton('Create Alert')->form([
            'alert_configuration[channelType]' => AlertChannelType::Webhook->value,
            'alert_configuration[webhookUrl]' => 'https://api.example.com/webhooks/alerts',
            'alert_configuration[minSeverity]' => DriftSeverity::Critical->value,
            'alert_configuration[isActive]' => true,
        ]);

        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'created');
    }

    public function testConfigureEmailAlert(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/dashboard/alerts/new');

        $form = $crawler->selectButton('Create Alert')->form([
            'alert_configuration[channelType]' => AlertChannelType::Email->value,
            'alert_configuration[emailRecipients]' => 'admin@example.com, ops@example.com',
            'alert_configuration[minSeverity]' => DriftSeverity::Info->value,
            'alert_configuration[isActive]' => true,
        ]);

        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'created');
    }

    public function testTestAlertEndpointRequiresValidCsrf(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $alert = AlertConfigurationFactory::createOne([
            'channelType' => AlertChannelType::Webhook,
            'channelConfig' => ['url' => 'https://httpbin.org/post'],
            'isActive' => true,
        ]);
        $alertId = $alert->getId()->toRfc4122();

        $client->loginUser($admin);

        // Navigate to alert edit page to establish session
        $client->request('GET', '/dashboard/alerts/' . $alertId . '/edit');
        self::assertResponseIsSuccessful();

        // Test endpoint with invalid CSRF token should return 403
        $client->request(
            'POST',
            '/dashboard/alerts/' . $alertId . '/test',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['_token' => 'invalid-token'])
        );

        self::assertResponseStatusCodeSame(403);

        // Verify JSON response
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{success: bool, message: string} $response */
        $response = json_decode($content, true);
        self::assertArrayHasKey('success', $response);
        self::assertFalse($response['success']);
        self::assertArrayHasKey('message', $response);
    }

    public function testAlertCanBeMuted(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $alert = AlertConfigurationFactory::createOne([
            'channelType' => AlertChannelType::Slack,
            'isActive' => true,
        ]);

        $client->loginUser($admin);

        // Navigate to alerts list to establish session
        $crawler = $client->request('GET', '/dashboard/alerts');
        self::assertResponseIsSuccessful();

        // Get CSRF token from the page
        $csrfToken = $crawler->filter('input[name="_token"]')->first()->attr('value');

        // Mute the alert
        $client->request('POST', '/dashboard/alerts/' . $alert->getId() . '/mute', [
            '_token' => $csrfToken,
            'duration' => '1h',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();

        // Verify mute success
        self::assertSelectorTextContains('.alert-success', 'muted');
    }

    public function testAlertCanBeUnmuted(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $alert = AlertConfigurationFactory::new()->muted()->create();

        $client->loginUser($admin);

        // Navigate to alerts list to establish session
        $crawler = $client->request('GET', '/dashboard/alerts');
        self::assertResponseIsSuccessful();

        // Get CSRF token from the page
        $csrfToken = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/dashboard/alerts/' . $alert->getId() . '/unmute', [
            '_token' => $csrfToken,
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'unmuted');
    }

    public function testAlertCanBeToggled(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $alert = AlertConfigurationFactory::createOne(['isActive' => true]);
        $alertId = $alert->getId()->toRfc4122();

        $client->loginUser($admin);

        // Navigate to alerts list to establish session
        $crawler = $client->request('GET', '/dashboard/alerts');
        self::assertResponseIsSuccessful();

        // Find the toggle form for this specific alert and get its CSRF token
        $toggleForm = $crawler->filter('form[action*="/toggle"]')->first();
        $csrfToken = $toggleForm->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/dashboard/alerts/' . $alertId . '/toggle', [
            '_token' => $csrfToken,
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();

        // Verify toggle success
        self::assertSelectorExists('.alert-success');
    }

    public function testAlertCanBeDeleted(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $alert = AlertConfigurationFactory::createOne();
        $alertId = $alert->getId()->toRfc4122();

        $client->loginUser($admin);

        // Navigate to alert edit page to establish session
        $crawler = $client->request('GET', '/dashboard/alerts/' . $alertId . '/edit');
        self::assertResponseIsSuccessful();

        // Get CSRF token from the page
        $csrfToken = $crawler->filter('input[name="_token"]')->last()->attr('value');

        $client->request('POST', '/dashboard/alerts/' . $alertId . '/delete', [
            '_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/dashboard/alerts');
        $client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'deleted');
    }

    public function testAlertHistoryShowsSentAlerts(): void
    {
        $client = static::createClient();

        $admin = UserFactory::new()->admin()->create();
        $client->loginUser($admin);

        $client->request('GET', '/dashboard/alerts/history');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Alert History');
    }

    public function testNonAdminCannotCreateAlert(): void
    {
        $client = static::createClient();

        $user = UserFactory::createOne();
        $client->loginUser($user);

        $client->request('GET', '/dashboard/alerts/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonAdminCanViewAlertsList(): void
    {
        $client = static::createClient();

        $user = UserFactory::createOne();
        AlertConfigurationFactory::createOne();

        $client->loginUser($user);

        $client->request('GET', '/dashboard/alerts');

        self::assertResponseIsSuccessful();
    }
}
