<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\ApiSchema;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Repository\ApiSchemaRepository;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ImportSchemaCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;
    private ApiSchemaRepository $schemaRepository;
    private string $tempDir;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:schema:import');
        $this->commandTester = new CommandTester($command);

        $this->schemaRepository = self::getContainer()->get(ApiSchemaRepository::class);

        $this->tempDir = sys_get_temp_dir() . '/sentinel_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function importsSchemaFromFile(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'import-test']);
        $schemaFile = $this->createSchemaFile([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
            ],
        ]);

        $this->commandTester->execute([
            'file' => $schemaFile,
            '--token' => 'import-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Schema imported successfully', $output);
        self::assertStringContainsString('GET https://api.example.com/users', $output);
        self::assertStringContainsString('response', $output);

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'https://api.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );
        self::assertCount(1, $schemas);
        self::assertSame(1, $schemas[0]->getVersion());
        self::assertSame(0, $schemas[0]->getSampleCount());
        self::assertFalse($schemas[0]->isMaster());
    }

    #[Test]
    public function importsSchemaAsMaster(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'master-test']);
        $schemaFile = $this->createSchemaFile(['type' => 'object']);

        $this->commandTester->execute([
            'file' => $schemaFile,
            '--token' => 'master-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
            '--master' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Master', $this->commandTester->getDisplay());

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'https://api.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );
        self::assertCount(1, $schemas);
        self::assertTrue($schemas[0]->isMaster());
    }

    #[Test]
    public function demotesExistingMasterWhenImportingNewMaster(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'demote-test']);
        $existingMaster = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'https://api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => true,
            'version' => 1,
        ]);

        $schemaFile = $this->createSchemaFile(['type' => 'object', 'properties' => ['new' => ['type' => 'string']]]);

        $this->commandTester->execute([
            'file' => $schemaFile,
            '--token' => 'demote-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
            '--master' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $updatedExistingMaster = $this->schemaRepository->find($existingMaster->getId());
        self::assertNotNull($updatedExistingMaster);
        self::assertFalse($updatedExistingMaster->isMaster());

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'https://api.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );
        $newSchema = array_filter($schemas, fn (ApiSchema $s) => $s->getVersion() === 2);
        self::assertCount(1, $newSchema);
        self::assertTrue(array_values($newSchema)[0]->isMaster());
    }

    #[Test]
    public function incrementsVersionForExistingEndpoint(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'version-test']);
        ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'https://api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 3,
        ]);

        $schemaFile = $this->createSchemaFile(['type' => 'object']);

        $this->commandTester->execute([
            'file' => $schemaFile,
            '--token' => 'version-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Version       4', $output);
    }

    #[Test]
    public function dryRunDoesNotPersist(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'dryrun-test']);
        $schemaFile = $this->createSchemaFile(['type' => 'object']);

        $this->commandTester->execute([
            'file' => $schemaFile,
            '--token' => 'dryrun-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
            '--dry-run' => true,
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Dry run', $output);

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'https://api.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );
        self::assertCount(0, $schemas);
    }

    #[Test]
    public function failsWhenFileNotFound(): void
    {
        ApiTokenFactory::createOne(['name' => 'file-test']);

        $this->commandTester->execute([
            'file' => '/nonexistent/schema.json',
            '--token' => 'file-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('File not found', $output);
    }

    #[Test]
    public function failsWhenFileContainsInvalidJson(): void
    {
        ApiTokenFactory::createOne(['name' => 'json-test']);
        $invalidFile = $this->tempDir . '/invalid.json';
        file_put_contents($invalidFile, '{ invalid json }');

        $this->commandTester->execute([
            'file' => $invalidFile,
            '--token' => 'json-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid JSON', $output);
    }

    #[Test]
    public function failsWhenTokenNotFound(): void
    {
        $schemaFile = $this->createSchemaFile(['type' => 'object']);

        $this->commandTester->execute([
            'file' => $schemaFile,
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
    public function failsWhenSchemaTypeInvalid(): void
    {
        ApiTokenFactory::createOne(['name' => 'type-test']);
        $schemaFile = $this->createSchemaFile(['type' => 'object']);

        $this->commandTester->execute([
            'file' => $schemaFile,
            '--token' => 'type-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'invalid',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid schema type', $output);
        self::assertStringContainsString('request, response', $output);
    }

    #[Test]
    public function failsWhenRequiredOptionsAreMissing(): void
    {
        $schemaFile = $this->createSchemaFile(['type' => 'object']);

        $this->commandTester->execute([
            'file' => $schemaFile,
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Missing required options', $output);
    }

    #[Test]
    public function resolvesTokenByUuid(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'uuid-test']);
        $schemaFile = $this->createSchemaFile(['type' => 'object']);

        $this->commandTester->execute([
            'file' => $schemaFile,
            '--token' => $token->getId()->toRfc4122(),
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'GET',
            '--type' => 'response',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('uuid-test', $this->commandTester->getDisplay());
    }

    #[Test]
    public function normalizesHttpMethodToUppercase(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'method-test']);
        $schemaFile = $this->createSchemaFile(['type' => 'object']);

        $this->commandTester->execute([
            'file' => $schemaFile,
            '--token' => 'method-test',
            '--host' => 'https://api.example.com',
            '--endpoint' => '/users',
            '--method' => 'post',
            '--type' => 'request',
        ]);

        $output = $this->commandTester->getDisplay();

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('POST', $output);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function createSchemaFile(array $schema): string
    {
        $filePath = $this->tempDir . '/schema_' . uniqid() . '.json';
        file_put_contents($filePath, json_encode($schema, JSON_PRETTY_PRINT));
        return $filePath;
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
