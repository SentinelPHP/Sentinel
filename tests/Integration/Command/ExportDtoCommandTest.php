<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\GeneratedDtoFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ExportDtoCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;
    private string $tempDir;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:dto:export');
        $this->commandTester = new CommandTester($command);

        $this->tempDir = sys_get_temp_dir() . '/sentinel_dto_export_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function exportsDtoBySchema(): void
    {
        $schema = ApiSchemaFactory::createOne([
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'GetUsersResponse',
            'namespace' => 'App\\Dto\\Generated',
            'phpCode' => "<?php\n\nnamespace App\\Dto\\Generated;\n\nclass GetUsersResponse {}",
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            '--schema-id' => $schema->getId()->toRfc4122(),
            '--output-dir' => $this->tempDir,
            '--force' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Exported', $output);
        self::assertStringContainsString('file(s)', $output);

        self::assertFileExists($this->tempDir . '/GetUsersResponse.php');
    }

    #[Test]
    public function exportsDtoInDryRunMode(): void
    {
        $schema = ApiSchemaFactory::createOne();
        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'DryRunResponse',
            'namespace' => 'App\\Dto\\Generated',
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            '--schema-id' => $schema->getId()->toRfc4122(),
            '--output-dir' => $this->tempDir,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Would write', $output);

        self::assertFileDoesNotExist($this->tempDir . '/DryRunResponse.php');
    }

    #[Test]
    public function exportsDtosByToken(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Export Token']);

        $schema1 = ApiSchemaFactory::createOne(['token' => $token, 'endpointPath' => '/a']);
        $schema2 = ApiSchemaFactory::createOne(['token' => $token, 'endpointPath' => '/b']);

        GeneratedDtoFactory::createOne([
            'schema' => $schema1,
            'className' => 'ResponseA',
            'namespace' => 'App\\Dto\\Generated',
            'isCurrent' => true,
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema2,
            'className' => 'ResponseB',
            'namespace' => 'App\\Dto\\Generated',
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            '--token' => 'Export Token',
            '--output-dir' => $this->tempDir,
            '--force' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Found 2 DTO(s)', $output);
        self::assertStringContainsString('Exported', $output);
    }

    #[Test]
    public function exportsAllDtos(): void
    {
        $schema1 = ApiSchemaFactory::createOne();
        $schema2 = ApiSchemaFactory::createOne();

        GeneratedDtoFactory::createOne([
            'schema' => $schema1,
            'className' => 'AllResponse1',
            'namespace' => 'App\\Dto\\Generated',
            'isCurrent' => true,
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema2,
            'className' => 'AllResponse2',
            'namespace' => 'App\\Dto\\Generated',
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            '--all' => true,
            '--output-dir' => $this->tempDir,
            '--force' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Exported', $output);
    }

    #[Test]
    public function exportsBundledFormat(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Bundled Token']);

        $schema1 = ApiSchemaFactory::createOne(['token' => $token]);
        $schema2 = ApiSchemaFactory::createOne(['token' => $token]);

        GeneratedDtoFactory::createOne([
            'schema' => $schema1,
            'className' => 'BundledA',
            'namespace' => 'App\\Dto\\Generated',
            'phpCode' => "<?php\n\nclass BundledA {}",
            'isCurrent' => true,
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema2,
            'className' => 'BundledB',
            'namespace' => 'App\\Dto\\Generated',
            'phpCode' => "<?php\n\nclass BundledB {}",
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            '--token' => 'Bundled Token',
            '--format' => 'bundled',
            '--output-dir' => $this->tempDir,
            '--force' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Exported', $output);

        self::assertFileExists($this->tempDir . '/Bundled_Token_dtos.php');
    }

    #[Test]
    public function skipsExistingFilesWithoutForce(): void
    {
        $schema = ApiSchemaFactory::createOne();
        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'ExistingResponse',
            'namespace' => 'App\\Dto\\Generated',
            'isCurrent' => true,
        ]);

        // Create existing file
        file_put_contents($this->tempDir . '/ExistingResponse.php', '<?php // existing');

        $this->commandTester->execute([
            '--schema-id' => $schema->getId()->toRfc4122(),
            '--output-dir' => $this->tempDir,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Skipped', $output);

        // File should be unchanged
        $content = file_get_contents($this->tempDir . '/ExistingResponse.php');
        self::assertIsString($content);
        self::assertStringContainsString('// existing', $content);
    }

    #[Test]
    public function overwritesExistingFilesWithForce(): void
    {
        $schema = ApiSchemaFactory::createOne();
        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'OverwriteResponse',
            'namespace' => 'App\\Dto\\Generated',
            'phpCode' => "<?php\n\nclass OverwriteResponse { /* new */ }",
            'isCurrent' => true,
        ]);

        // Create existing file
        file_put_contents($this->tempDir . '/OverwriteResponse.php', '<?php // old');

        $this->commandTester->execute([
            '--schema-id' => $schema->getId()->toRfc4122(),
            '--output-dir' => $this->tempDir,
            '--force' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Exported', $output);

        // File should be overwritten
        $content = file_get_contents($this->tempDir . '/OverwriteResponse.php');
        self::assertIsString($content);
        self::assertStringContainsString('/* new */', $content);
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
    public function failsWhenFormatIsInvalid(): void
    {
        $this->commandTester->execute([
            '--all' => true,
            '--format' => 'invalid-format',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Invalid format', $output);
    }

    #[Test]
    public function showsInfoWhenNoDtosForToken(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Empty Token']);

        $this->commandTester->execute([
            '--token' => 'Empty Token',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('No DTOs found', $output);
    }

    #[Test]
    public function failsWhenNoDtoForSchema(): void
    {
        $schema = ApiSchemaFactory::createOne();

        $this->commandTester->execute([
            '--schema-id' => $schema->getId()->toRfc4122(),
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('No DTO found', $output);
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
