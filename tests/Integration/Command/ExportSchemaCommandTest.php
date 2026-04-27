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

final class ExportSchemaCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;
    private string $tempDir;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:schema:export');
        $this->commandTester = new CommandTester($command);

        $this->tempDir = sys_get_temp_dir() . '/sentinel_export_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function exportsSchemaById(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'export-test']);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'https://api.example.com',
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
            '--id' => $schema->getId()->toRfc4122(),
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('"type": "object"', $output);
        self::assertStringContainsString('"id"', $output);
        self::assertStringContainsString('"name"', $output);
    }

    #[Test]
    public function exportsSchemaByFilters(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'filter-test']);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'https://api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => true,
            'jsonSchema' => ['type' => 'object'],
        ]);

        $this->commandTester->execute([
            '--token' => 'filter-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('"type": "object"', $output);
    }

    #[Test]
    public function exportsSchemaToFile(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'file-test']);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'https://api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'jsonSchema' => ['type' => 'object'],
        ]);

        $outputFile = $this->tempDir . '/exported.json';

        $this->commandTester->execute([
            '--id' => $schema->getId()->toRfc4122(),
            '--output' => $outputFile,
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Schema exported to', $output);
        self::assertFileExists($outputFile);

        $content = file_get_contents($outputFile);
        self::assertIsString($content);
        self::assertStringContainsString('"type": "object"', $content);
    }

    #[Test]
    public function exportsSchemaAsOpenApi(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'openapi-test']);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'https://api.example.com',
            'endpointPath' => '/users/{id}',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 2,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
            ],
        ]);

        $this->commandTester->execute([
            '--id' => $schema->getId()->toRfc4122(),
            '--format' => 'openapi',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('"openapi": "3.0.3"', $output);
        self::assertStringContainsString('"/users/{id}"', $output);
        self::assertStringContainsString('"get"', $output);
        self::assertStringContainsString('"responses"', $output);
        self::assertStringContainsString('"200"', $output);
        self::assertStringContainsString('"application/json"', $output);
    }

    #[Test]
    public function exportsRequestSchemaAsOpenApiWithRequestBody(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'openapi-request-test']);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'https://api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'POST',
            'schemaType' => SchemaType::Request,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
            ],
        ]);

        $this->commandTester->execute([
            '--id' => $schema->getId()->toRfc4122(),
            '--format' => 'openapi',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('"requestBody"', $output);
        self::assertStringContainsString('"required": true', $output);
    }

    #[Test]
    public function exportsWithNoPrettyOption(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'compact-test']);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'jsonSchema' => ['type' => 'object'],
        ]);

        $this->commandTester->execute([
            '--id' => $schema->getId()->toRfc4122(),
            '--no-pretty' => true,
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('{"type":"object"}', $output);
    }

    #[Test]
    public function failsWhenSchemaIdNotFound(): void
    {
        $this->commandTester->execute([
            '--id' => '01936f8a-1234-7abc-8def-0123456789ab',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Schema not found', $output);
    }

    #[Test]
    public function failsWhenSchemaIdIsInvalidUuid(): void
    {
        $this->commandTester->execute([
            '--id' => 'not-a-uuid',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid UUID', $output);
    }

    #[Test]
    public function failsWhenNoMasterSchemaFound(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'no-master-test']);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'https://api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => false,
        ]);

        $this->commandTester->execute([
            '--token' => 'no-master-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('No master schema found', $output);
    }

    #[Test]
    public function failsWhenTokenNotFound(): void
    {
        $this->commandTester->execute([
            '--token' => 'nonexistent-token',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Token not found', $output);
    }

    #[Test]
    public function failsWhenFormatIsInvalid(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'format-test']);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        $this->commandTester->execute([
            '--id' => $schema->getId()->toRfc4122(),
            '--format' => 'invalid-format',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid format', $output);
    }

    #[Test]
    public function failsWhenFilterOptionsAreMissing(): void
    {
        $this->commandTester->execute([
            '--token' => 'some-token',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Either --id or all filter options', $output);
    }

    #[Test]
    public function failsWhenSchemaTypeIsInvalid(): void
    {
        ApiTokenFactory::createOne(['name' => 'type-test']);

        $this->commandTester->execute([
            '--token' => 'type-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'invalid',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid schema type', $output);
    }

    #[Test]
    public function resolvesTokenByUuid(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'uuid-token-test']);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'https://api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => true,
            'jsonSchema' => ['type' => 'object'],
        ]);

        $this->commandTester->execute([
            '--token' => $token->getId()->toRfc4122(),
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
