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
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class GenerateDtoCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:dto:generate');
        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function generatesDtoForSchema(): void
    {
        $schema = ApiSchemaFactory::createOne([
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
            '--schema-id' => $schema->getId()->toRfc4122(),
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Generated and stored', $output);
        self::assertStringContainsString('DTO(s)', $output);
    }

    #[Test]
    public function generatesDtoInDryRunMode(): void
    {
        $schema = ApiSchemaFactory::createOne([
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ],
        ]);

        $this->commandTester->execute([
            '--schema-id' => $schema->getId()->toRfc4122(),
            '--dry-run' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Dry run mode', $output);
        self::assertStringContainsString('Generated PHP Code', $output);
        self::assertStringContainsString('class', $output);
    }

    #[Test]
    public function generatesAllDtosForToken(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Generate Token']);

        ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'isMaster' => true,
            'jsonSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ]);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/orders',
            'httpMethod' => 'GET',
            'isMaster' => true,
            'jsonSchema' => ['type' => 'object', 'properties' => ['orderId' => ['type' => 'string']]],
        ]);

        $this->commandTester->execute([
            '--token' => 'Generate Token',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Found 2 master schema(s)', $output);
        self::assertStringContainsString('Generated', $output);
    }

    #[Test]
    public function generatesAllDtos(): void
    {
        $token = ApiTokenFactory::createOne();

        ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/all-1',
            'isMaster' => true,
            'jsonSchema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        ]);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/all-2',
            'isMaster' => true,
            'jsonSchema' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
        ]);

        $this->commandTester->execute([
            '--all' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Found 2 master schema(s)', $output);
    }

    #[Test]
    public function filtersByEndpoint(): void
    {
        $token = ApiTokenFactory::createOne();

        ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/users/profile',
            'isMaster' => true,
            'jsonSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ]);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/orders/list',
            'isMaster' => true,
            'jsonSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ]);

        $this->commandTester->execute([
            '--endpoint' => 'users',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Found 1 master schema(s)', $output);
    }

    #[Test]
    public function failsWhenSchemaNotFound(): void
    {
        $this->commandTester->execute([
            '--schema-id' => '01936f8a-1234-7abc-8def-0123456789ab',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Schema not found', $output);
    }

    #[Test]
    public function failsWhenUuidIsInvalid(): void
    {
        $this->commandTester->execute([
            '--schema-id' => 'not-a-uuid',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Invalid UUID format', $output);
    }

    #[Test]
    public function failsWhenTokenNotFound(): void
    {
        $this->commandTester->execute([
            '--token' => 'Non Existent Token',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Token not found', $output);
    }

    #[Test]
    public function failsWhenNoOptionsProvided(): void
    {
        $this->commandTester->execute([]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Please specify', $output);
    }

    #[Test]
    public function showsInfoWhenNoMasterSchemas(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Empty Token']);

        $this->commandTester->execute([
            '--token' => 'Empty Token',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('No master schemas found', $output);
    }

    #[Test]
    public function skipsNonMasterSchemas(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Mixed Token']);

        ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/master',
            'isMaster' => true,
            'jsonSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ]);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/learning',
            'isMaster' => false,
            'jsonSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ]);

        $this->commandTester->execute([
            '--token' => 'Mixed Token',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Found 1 master schema(s)', $output);
    }

    #[Test]
    public function reportsUnchangedDtos(): void
    {
        $schema = ApiSchemaFactory::createOne([
            'endpointPath' => '/unchanged',
            'httpMethod' => 'GET',
            'jsonSchema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
            ],
        ]);

        // Generate first time
        $this->commandTester->execute([
            '--schema-id' => $schema->getId()->toRfc4122(),
        ]);
        self::assertSame(0, $this->commandTester->getStatusCode());

        // Generate again - should be unchanged
        $this->commandTester->execute([
            '--schema-id' => $schema->getId()->toRfc4122(),
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('unchanged', $output);
    }
}
