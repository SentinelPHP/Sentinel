<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use SentinelPHP\Dto\Enum\SchemaType;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ShowSchemaCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:schema:show');
        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function displaysSchemaJson(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Test Token']);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
            ],
        ]);

        $this->commandTester->execute([
            'schema-id' => $schema->getId()->toRfc4122(),
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('"type": "object"', $output);
        self::assertStringContainsString('"id"', $output);
        self::assertStringContainsString('"name"', $output);
        self::assertStringContainsString('Test Token', $output);
    }

    #[Test]
    public function failsForInvalidUuid(): void
    {
        $this->commandTester->execute([
            'schema-id' => 'not-a-uuid',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid UUID format', $output);
    }

    #[Test]
    public function failsWhenSchemaNotFound(): void
    {
        $uuid = Uuid::v7();

        $this->commandTester->execute([
            'schema-id' => $uuid->toRfc4122(),
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Schema not found', $output);
    }

    #[Test]
    public function outputsRawJsonWhenRequested(): void
    {
        $schema = ApiSchemaFactory::createOne([
            'jsonSchema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
            ],
        ]);

        $this->commandTester->execute([
            'schema-id' => $schema->getId()->toRfc4122(),
            '--raw' => true,
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringNotContainsString('ID', $output);
        self::assertStringNotContainsString('Token', $output);
        self::assertJson(trim($output));
    }

    #[Test]
    public function showsDiffBetweenVersions(): void
    {
        $token = ApiTokenFactory::createOne();

        $schemaV1 = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 1,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ],
        ]);

        $schemaV2 = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 2,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
            ],
        ]);

        $this->commandTester->execute([
            'schema-id' => $schemaV2->getId()->toRfc4122(),
            '--diff' => 'previous',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('v1 → v2', $output);
        self::assertStringContainsString('+ properties.name', $output);
    }

    #[Test]
    public function warnsWhenOnlyOneVersionExists(): void
    {
        $schema = ApiSchemaFactory::createOne([
            'version' => 1,
            'jsonSchema' => ['type' => 'object'],
        ]);

        $this->commandTester->execute([
            'schema-id' => $schema->getId()->toRfc4122(),
            '--diff' => 'previous',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Only one version exists', $output);
    }

    #[Test]
    public function failsWhenDiffVersionNotFound(): void
    {
        $token = ApiTokenFactory::createOne();

        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 2,
        ]);

        $schemaV3 = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 3,
        ]);

        $this->commandTester->execute([
            'schema-id' => $schemaV3->getId()->toRfc4122(),
            '--diff' => '1',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Could not find version to compare', $output);
    }
}
