<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class AbstractPantherTestCase extends WebTestCase
{
    private const TEST_USER_EMAIL = 'panther-test@example.com';
    private const TEST_ADMIN_EMAIL = 'panther-admin@example.com';
    private const TEST_PASSWORD = 'password';

    protected ?KernelBrowser $client = null;

    protected function loginAs(User $user): void
    {
        $client = $this->getPantherClient();
        $client->loginUser($user);
        $client->request('GET', '/dashboard');
        $this->assertElementExists('.sidebar');
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
        $container = $this->getPantherClient()->getContainer();
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

    protected function getPantherClient(): KernelBrowser
    {
        if ($this->client === null) {
            $this->client = static::createClient();
        }

        return $this->client;
    }

    protected function waitForLiveComponent(string $selector, int $timeout = 5): void
    {
        $this->assertElementExists($selector . '[data-live-id]');
    }

    protected function waitForStimulusController(string $controller, int $timeout = 5): void
    {
        $this->assertElementExists(sprintf('[data-controller*="%s"]', $controller));
    }

    protected function takeScreenshot(string $name): void
    {
        $projectDir = self::$kernel?->getProjectDir() ?? getcwd();
        $screenshotDir = $projectDir . '/var/error-screenshots';
        if (!is_dir($screenshotDir)) {
            mkdir($screenshotDir, 0777, true);
        }
        
        $filename = sprintf('%s/%s_%s', $screenshotDir, date('Y-m-d_H-i-s'), $name);

        // KernelBrowser cannot capture image screenshots; persist HTML for debugging.
        file_put_contents($filename . '.html', $this->getPantherClient()->getResponse()->getContent() ?: '');
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
        $this->client = null;
        parent::tearDown();
    }
}
