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

final class ListSchemaCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:schema:list');
        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function listsAllSchemas(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Test Token']);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => true,
            'version' => 2,
            'sampleCount' => 5,
        ]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('GET', $output);
        self::assertStringContainsString('api.example.com/users', $output);
        self::assertStringContainsString('response', $output);
        self::assertStringContainsString('Test Token', $output);
        self::assertStringContainsString('✓', $output);
        self::assertStringContainsString('Total: 1 schema(s)', $output);
    }

    #[Test]
    public function showsInfoWhenNoSchemasFound(): void
    {
        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('No schemas found', $output);
    }

    #[Test]
    public function filtersSchemasByTokenName(): void
    {
        $token1 = ApiTokenFactory::createOne(['name' => 'Token Alpha']);
        $token2 = ApiTokenFactory::createOne(['name' => 'Token Beta']);

        ApiSchemaFactory::createOne([
            'token' => $token1,
            'targetHost' => 'api.alpha.com',
            'endpointPath' => '/data',
            'httpMethod' => 'GET',
        ]);
        ApiSchemaFactory::createOne([
            'token' => $token2,
            'targetHost' => 'api.beta.com',
            'endpointPath' => '/data',
            'httpMethod' => 'GET',
        ]);

        $this->commandTester->execute(['--token' => 'Token Alpha']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('api.alpha.com', $output);
        self::assertStringNotContainsString('api.beta.com', $output);
        self::assertStringContainsString('Total: 1 schema(s)', $output);
    }

    #[Test]
    public function filtersSchemasByTokenUuid(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'UUID Token']);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.uuid.com',
            'endpointPath' => '/test',
            'httpMethod' => 'POST',
        ]);
        ApiSchemaFactory::createOne(['targetHost' => 'api.other.com']);

        $this->commandTester->execute(['--token' => $token->getId()->toRfc4122()]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('api.uuid.com', $output);
        self::assertStringNotContainsString('api.other.com', $output);
    }

    #[Test]
    public function filtersSchemasByHost(): void
    {
        ApiSchemaFactory::createOne(['targetHost' => 'api.production.com']);
        ApiSchemaFactory::createOne(['targetHost' => 'api.staging.com']);

        $this->commandTester->execute(['--host' => 'production']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('api.production.com', $output);
        self::assertStringNotContainsString('api.staging.com', $output);
    }

    #[Test]
    public function filtersSchemasByEndpoint(): void
    {
        ApiSchemaFactory::createOne([
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users/profile',
        ]);
        ApiSchemaFactory::createOne([
            'targetHost' => 'api.example.com',
            'endpointPath' => '/orders/list',
        ]);

        $this->commandTester->execute(['--endpoint' => 'users']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('/users/profile', $output);
        self::assertStringNotContainsString('/orders/list', $output);
    }

    #[Test]
    public function filtersMasterSchemasOnly(): void
    {
        $token = ApiTokenFactory::createOne();
        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/master-endpoint',
            'isMaster' => true,
        ]);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/learning-endpoint',
            'isMaster' => false,
        ]);

        $this->commandTester->execute(['--master-only' => true]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('/master-endpoint', $output);
        self::assertStringNotContainsString('/learning-endpoint', $output);
        self::assertStringContainsString('Total: 1 schema(s)', $output);
    }

    #[Test]
    public function respectsLimitOption(): void
    {
        $token = ApiTokenFactory::createOne();
        ApiSchemaFactory::createMany(5, ['token' => $token]);

        $this->commandTester->execute(['--limit' => '2']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Showing 2 of 5 total schemas', $output);
    }

    #[Test]
    public function combinesMultipleFilters(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Multi Filter Token']);

        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.target.com',
            'endpointPath' => '/match',
            'isMaster' => true,
        ]);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.target.com',
            'endpointPath' => '/match',
            'isMaster' => false,
        ]);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.other.com',
            'endpointPath' => '/match',
            'isMaster' => true,
        ]);

        $this->commandTester->execute([
            '--token' => 'Multi Filter Token',
            '--host' => 'target',
            '--master-only' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Total: 1 schema(s)', $output);
        self::assertStringContainsString('api.target.com', $output);
    }

    #[Test]
    public function failsWithNonExistentToken(): void
    {
        $this->commandTester->execute(['--token' => 'Non Existent Token']);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Token not found', $output);
    }

    #[Test]
    public function truncatesLongEndpoints(): void
    {
        ApiSchemaFactory::createOne([
            'targetHost' => 'api.example.com',
            'endpointPath' => '/very/long/endpoint/path/that/exceeds/the/maximum/display/width',
        ]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('...', $output);
    }
}
