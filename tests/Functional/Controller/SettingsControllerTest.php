<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Entity\UserPreferences;
use App\Tests\Factories\UserFactory;
use App\Tests\Factories\UserPreferencesFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class SettingsControllerTest extends WebTestCase
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

    public function testIndexReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dashboard/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.header-title', 'Settings');
    }

    public function testIndexShowsSettingsForm(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
        self::assertSelectorExists('#user_preferences_defaultDateRange');
        self::assertSelectorExists('#user_preferences_refreshInterval');
        self::assertSelectorExists('#user_preferences_theme');
        self::assertSelectorExists('#user_preferences_timezone');
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/dashboard/settings');

        self::assertResponseRedirects('/login');
    }

    public function testSaveSettingsUpdatesPreferences(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/settings');

        $form = $crawler->selectButton('Save Settings')->form([
            'user_preferences[defaultDateRange]' => '7d',
            'user_preferences[refreshInterval]' => '60000',
            'user_preferences[theme]' => 'dark',
            'user_preferences[timezone]' => 'America/New_York',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects('/dashboard/settings');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Settings saved successfully');
    }

    public function testSaveSettingsValidatesDateRange(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/settings');

        $form = $crawler->selectButton('Save Settings')->form([
            'user_preferences[defaultDateRange]' => '24h',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects('/dashboard/settings');
    }

    public function testSettingsPageShowsExistingPreferences(): void
    {
        UserPreferencesFactory::createOne([
            'user' => $this->user,
            'theme' => 'dark',
            'timezone' => 'Europe/London',
            'defaultDateRange' => '7d',
            'refreshInterval' => 60000,
        ]);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/settings');

        self::assertResponseIsSuccessful();
        
        $themeSelect = $crawler->filter('#user_preferences_theme');
        self::assertSame('dark', $themeSelect->filter('option[selected]')->attr('value'));
        
        $timezoneSelect = $crawler->filter('#user_preferences_timezone');
        self::assertSame('Europe/London', $timezoneSelect->filter('option[selected]')->attr('value'));
    }

    public function testSettingsPageShowsNotificationEventCheckboxes(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="user_preferences[notificationEvents][]"]');
    }

    public function testSaveNotificationEventsPreferences(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/settings');

        $form = $crawler->selectButton('Save Settings')->form([
            'user_preferences[notificationEvents]' => ['drift_detected'],
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects('/dashboard/settings');
    }

    public function testThemePreviewButtonsExist(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/dashboard/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button.theme-preview[data-theme="light"]');
        self::assertSelectorExists('button.theme-preview[data-theme="dark"]');
    }
}
