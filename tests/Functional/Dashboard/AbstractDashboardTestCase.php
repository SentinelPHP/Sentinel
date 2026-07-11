<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class AbstractDashboardTestCase extends WebTestCase
{
    private const TEST_USER_EMAIL = 'dashboard-test@example.com';
    private const TEST_ADMIN_EMAIL = 'dashboard-admin@example.com';
    private const TEST_PASSWORD = 'password';

    protected ?KernelBrowser $client = null;

    protected function loginAs(User $user): void
    {
        $client = $this->getBrowser();
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
        $container = $this->getBrowser()->getContainer();
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

    protected function getBrowser(): KernelBrowser
    {
        if ($this->client === null) {
            $this->client = static::createClient();
        }

        return $this->client;
    }

    protected function assertLiveComponentExists(string $selector): void
    {
        $this->assertElementExists($selector . '[data-live-id]');
    }

    protected function assertStimulusControllerExists(string $controller): void
    {
        $this->assertElementExists(sprintf('[data-controller*="%s"]', $controller));
    }

    protected function saveResponseSnapshot(string $name): void
    {
        $projectDir = self::$kernel?->getProjectDir() ?? getcwd();
        $snapshotDir = $projectDir . '/var/error-snapshots';
        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0777, true);
        }

        $filename = sprintf('%s/%s_%s.html', $snapshotDir, date('Y-m-d_H-i-s'), $name);

        file_put_contents($filename, $this->getBrowser()->getResponse()->getContent() ?: '');
    }

    protected function assertElementExists(string $selector, string $message = ''): void
    {
        $elements = $this->getBrowser()->getCrawler()->filter($selector);
        $this->assertGreaterThan(0, $elements->count(), $message ?: "Element '$selector' not found");
    }

    protected function assertElementTextContains(string $selector, string $text, string $message = ''): void
    {
        $element = $this->getBrowser()->getCrawler()->filter($selector)->first();
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
