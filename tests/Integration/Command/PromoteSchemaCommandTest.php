<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\ApiSchema;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Enum\TokenMode;
use App\Repository\ApiSchemaRepository;
use App\Repository\ApiTokenRepository;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PromoteSchemaCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;
    private ApiSchemaRepository $schemaRepository;
    private ApiTokenRepository $tokenRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:schema:promote');
        $this->commandTester = new CommandTester($command);

        $this->schemaRepository = self::getContainer()->get(ApiSchemaRepository::class);
        $this->tokenRepository = self::getContainer()->get(ApiTokenRepository::class);
    }

    #[Test]
    public function promotesSchemaToMaster(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => false,
        ]);

        $this->commandTester->execute(['schema-id' => $schema->getId()->toRfc4122()]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $updatedSchema = $this->schemaRepository->find($schema->getId());
        self::assertNotNull($updatedSchema);
        self::assertTrue($updatedSchema->isMaster());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Schema promoted to master successfully', $output);
    }

    #[Test]
    public function demotesExistingMasterWhenPromotingNewSchema(): void
    {
        $token = ApiTokenFactory::createOne();

        $existingMaster = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => true,
            'version' => 1,
        ]);

        $newSchema = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => false,
            'version' => 2,
        ]);

        $this->commandTester->execute(['schema-id' => $newSchema->getId()->toRfc4122()]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $updatedExistingMaster = $this->schemaRepository->find($existingMaster->getId());
        $updatedNewSchema = $this->schemaRepository->find($newSchema->getId());

        self::assertNotNull($updatedExistingMaster);
        self::assertNotNull($updatedNewSchema);
        self::assertFalse($updatedExistingMaster->isMaster());
        self::assertTrue($updatedNewSchema->isMaster());
    }

    #[Test]
    public function switchesTokenModeFromLearningToValidating(): void
    {
        $token = ApiTokenFactory::createOne(['mode' => TokenMode::Learning]);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'isMaster' => false,
        ]);

        $this->commandTester->execute([
            'schema-id' => $schema->getId()->toRfc4122(),
            '--switch-mode' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $updatedToken = $this->tokenRepository->find($token->getId());
        self::assertNotNull($updatedToken);
        self::assertSame(TokenMode::Validating, $updatedToken->getMode());

        $output = (string) preg_replace('/[\s!]+/', ' ', $this->commandTester->getDisplay());
        self::assertStringContainsString('learning to validating', $output);
    }

    #[Test]
    public function warnsWhenTokenAlreadyInValidatingMode(): void
    {
        $token = ApiTokenFactory::createOne(['mode' => TokenMode::Validating]);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'isMaster' => false,
        ]);

        $this->commandTester->execute([
            'schema-id' => $schema->getId()->toRfc4122(),
            '--switch-mode' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('already in validating mode', $output);
    }

    #[Test]
    public function warnsWhenTokenInPassiveMode(): void
    {
        $token = ApiTokenFactory::createOne(['mode' => TokenMode::Passive]);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'isMaster' => false,
        ]);

        $this->commandTester->execute([
            'schema-id' => $schema->getId()->toRfc4122(),
            '--switch-mode' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('passive mode', $output);
        self::assertStringContainsString('only applies to learning mode', $output);
    }

    #[Test]
    public function warnsWhenSchemaAlreadyMaster(): void
    {
        $schema = ApiSchemaFactory::createOne(['isMaster' => true]);

        $this->commandTester->execute(['schema-id' => $schema->getId()->toRfc4122()]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('already the master schema', $output);
    }

    #[Test]
    public function failsWithInvalidUuid(): void
    {
        $this->commandTester->execute(['schema-id' => 'not-a-uuid']);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Invalid UUID format', $output);
    }

    #[Test]
    public function failsWhenSchemaNotFound(): void
    {
        $nonExistentId = Uuid::v7()->toRfc4122();

        $this->commandTester->execute(['schema-id' => $nonExistentId]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Schema not found', $output);
    }

    #[Test]
    public function displaysSchemaDetailsAfterPromotion(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Test API Token']);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 3,
            'sampleCount' => 10,
            'isMaster' => false,
        ]);

        $this->commandTester->execute(['schema-id' => $schema->getId()->toRfc4122()]);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('GET api.example.com/users', $output);
        self::assertStringContainsString('response', $output);
        self::assertStringContainsString('Test API Token', $output);
        self::assertStringContainsString('3', $output);
        self::assertStringContainsString('10', $output);
    }
}
