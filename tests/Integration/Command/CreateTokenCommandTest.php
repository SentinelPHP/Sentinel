<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\ApiToken;
use App\Enum\LogLevel;
use App\Repository\ApiTokenRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CreateTokenCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;
    private ApiTokenRepository $tokenRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:token:create');
        $this->commandTester = new CommandTester($command);

        $this->tokenRepository = self::getContainer()->get(ApiTokenRepository::class);
    }

    #[Test]
    public function createsTokenWithNameOnly(): void
    {
        $this->commandTester->execute(['name' => 'Test Token']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $token = $this->tokenRepository->findOneBy(['name' => 'Test Token']);
        self::assertNotNull($token);
        self::assertSame([], $token->getAllowedTargets());
        self::assertNull($token->getLogLevel());
        self::assertTrue($token->isActive());
    }

    #[Test]
    public function createsTokenWithTargetRestrictions(): void
    {
        $this->commandTester->execute([
            'name' => 'Stripe Token',
            '--targets' => ['api.stripe.com', '*.stripe.com'],
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $token = $this->tokenRepository->findOneBy(['name' => 'Stripe Token']);
        self::assertNotNull($token);
        self::assertSame(['api.stripe.com', '*.stripe.com'], $token->getAllowedTargets());
    }

    #[Test]
    public function createsTokenWithLogLevel(): void
    {
        $this->commandTester->execute([
            'name' => 'Debug Token',
            '--log-level' => 'full_audit',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $token = $this->tokenRepository->findOneBy(['name' => 'Debug Token']);
        self::assertNotNull($token);
        self::assertSame(LogLevel::FullAudit, $token->getLogLevel());
    }

    #[Test]
    public function failsWithInvalidLogLevel(): void
    {
        $this->commandTester->execute([
            'name' => 'Test Token',
            '--log-level' => 'invalid_level',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Invalid log level', $output);

        $token = $this->tokenRepository->findOneBy(['name' => 'Test Token']);
        self::assertNull($token);
    }

    #[Test]
    public function outputsPlainTokenForUser(): void
    {
        $this->commandTester->execute(['name' => 'Test Token']);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Bearer Token:', $output);
        self::assertStringContainsString('Save this token securely', $output);
        self::assertMatchesRegularExpression('/[a-f0-9]{64}/', $output);
    }

    #[Test]
    public function storedTokenHashMatchesOutputToken(): void
    {
        $this->commandTester->execute(['name' => 'Hash Test Token']);

        $output = $this->commandTester->getDisplay();

        $matchCount = preg_match('/([a-f0-9]{64})/', $output, $matches);
        self::assertSame(1, $matchCount);
        $plainToken = $matches[1];

        $token = $this->tokenRepository->findOneBy(['name' => 'Hash Test Token']);
        self::assertNotNull($token);
        self::assertSame(hash('sha256', $plainToken), $token->getTokenHash());
    }

    #[Test]
    public function createdTokenCanBeUsedForAuthentication(): void
    {
        $this->commandTester->execute(['name' => 'Auth Test Token']);

        $output = $this->commandTester->getDisplay();
        $matchCount = preg_match('/([a-f0-9]{64})/', $output, $matches);
        self::assertSame(1, $matchCount);
        $plainToken = $matches[1];

        $token = $this->tokenRepository->findActiveByTokenHash(hash('sha256', $plainToken));
        self::assertNotNull($token);
        self::assertSame('Auth Test Token', $token->getName());
    }
}
