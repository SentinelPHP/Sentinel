<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Entity\User;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class AbstractPantherTestCase extends PantherTestCase
{
    private const TEST_USER_EMAIL = 'panther-test@example.com';
    private const TEST_ADMIN_EMAIL = 'panther-admin@example.com';
    private const TEST_PASSWORD = 'password';

    protected static ?Client $pantherClient = null;

    protected function loginAs(User $user): void
    {
        $client = $this->getPantherClient();

        $client->request('GET', '/login');
        
        // Debug: take screenshot if login page doesn't load
        try {
            $client->waitFor('#inputEmail', 5);
        } catch (\Exception $e) {
            $this->takeScreenshot('login_page_failed');
            throw $e;
        }

        $client->getCrawler()->filter('#inputEmail')->sendKeys($user->getEmail());
        $client->getCrawler()->filter('#inputPassword')->sendKeys(self::TEST_PASSWORD);

        // Submit form via JavaScript (more reliable than click)
        $client->executeScript('document.querySelector("form").submit()');

        // Debug: take screenshot if login fails
        try {
            $client->waitFor('.sidebar', 10);
        } catch (\Exception $e) {
            $this->takeScreenshot('login_failed');
            throw $e;
        }
    }

    protected function loginAsUser(): User
    {
        $user = $this->getOrCreateTestUser(self::TEST_USER_EMAIL, ['ROLE_USER']);
        $this->loginAs($user);

        return $user;
    }

    protected function loginAsAdmin(): User
    {
        $admin = $this->getOrCreateTestUser(self::TEST_ADMIN_EMAIL, ['ROLE_ADMIN']);
        $this->loginAs($admin);

        return $admin;
    }

    /**
     * @param list<string> $roles
     */
    private function getOrCreateTestUser(string $email, array $roles): User
    {
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $userRepository = $em->getRepository(User::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = $userRepository->findOneBy(['email' => $email]);

        if ($user === null) {
            $user = new User();
            $user->setEmail($email); // @phpstan-ignore argument.type
            $user->setRoles($roles);
            $user->setPassword($passwordHasher->hashPassword($user, self::TEST_PASSWORD));

            $em->persist($user);
            $em->flush();
        }

        return $user;
    }

    protected function getPantherClient(): Client
    {
        if (self::$pantherClient === null) {
            // Use Selenium hub if configured (DDEV environment)
            $seleniumHub = $_ENV['PANTHER_SELENIUM_HUB'] ?? $_SERVER['PANTHER_SELENIUM_HUB'] ?? null;
            $externalBaseUri = $_ENV['PANTHER_EXTERNAL_BASE_URI'] ?? $_SERVER['PANTHER_EXTERNAL_BASE_URI'] ?? null;

            if (is_string($seleniumHub) && $seleniumHub !== '' && is_string($externalBaseUri) && $externalBaseUri !== '') {
                $chromeOptions = new ChromeOptions();
                $chromeOptions->addArguments([
                    '--headless',
                    '--disable-gpu',
                    '--no-sandbox',
                    '--disable-dev-shm-usage',
                    '--ignore-certificate-errors',
                ]);

                $capabilities = DesiredCapabilities::chrome();
                $capabilities->setCapability(ChromeOptions::CAPABILITY, $chromeOptions);

                self::$pantherClient = Client::createSeleniumClient(
                    $seleniumHub,
                    $capabilities,
                    $externalBaseUri
                );
            } else {
                // Local ChromeDriver
                self::$pantherClient = static::createPantherClient([
                    'browser' => static::CHROME,
                ]);
            }
        }

        return self::$pantherClient;
    }

    protected function waitForLiveComponent(string $selector, int $timeout = 5): void
    {
        $this->getPantherClient()->waitFor($selector . '[data-live-id]', $timeout);
    }

    protected function waitForStimulusController(string $controller, int $timeout = 5): void
    {
        $this->getPantherClient()->waitFor(
            sprintf('[data-controller*="%s"]', $controller),
            $timeout
        );
    }

    protected function takeScreenshot(string $name): void
    {
        $projectDir = self::$kernel?->getProjectDir() ?? getcwd();
        $screenshotDir = $projectDir . '/var/error-screenshots';
        if (!is_dir($screenshotDir)) {
            mkdir($screenshotDir, 0777, true);
        }
        
        $filename = sprintf('%s/%s_%s', $screenshotDir, date('Y-m-d_H-i-s'), $name);
        $this->getPantherClient()->takeScreenshot($filename . '.png');
        
        // Also save page source for debugging
        file_put_contents($filename . '.html', $this->getPantherClient()->getPageSource());
    }

    protected function assertElementExists(string $selector, string $message = ''): void
    {
        $elements = $this->getPantherClient()->getCrawler()->filter($selector);
        $this->assertGreaterThan(0, $elements->count(), $message ?: "Element '$selector' not found");
    }

    protected function assertElementTextContains(string $selector, string $text, string $message = ''): void
    {
        $element = $this->getPantherClient()->getCrawler()->filter($selector)->first();
        $this->assertStringContainsString(
            $text,
            $element->text(),
            $message ?: "Element '$selector' does not contain '$text'"
        );
    }

    protected function tearDown(): void
    {
        // Clear cookies and restart browser session for test isolation
        if (self::$pantherClient !== null) {
            try {
                self::$pantherClient->getCookieJar()->clear();
                self::$pantherClient->request('GET', '/logout');
            } catch (\Exception $e) {
                // Ignore errors during cleanup
            }
        }
        
        parent::tearDown();
        self::$pantherClient = null;
    }
}
