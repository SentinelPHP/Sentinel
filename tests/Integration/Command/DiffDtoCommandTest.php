<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\GeneratedDtoFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class DiffDtoCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:dto:diff');
        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function showsDiffBetweenVersions(): void
    {
        $schema = ApiSchemaFactory::createOne();

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'DiffResponse',
            'phpCode' => "<?php\n\nclass DiffResponse {\n    public int \$id;\n}",
            'version' => 1,
            'isCurrent' => false,
        ]);
        $currentDto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'DiffResponse',
            'phpCode' => "<?php\n\nclass DiffResponse {\n    public int \$id;\n    public string \$name;\n}",
            'version' => 2,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $currentDto->getId()->toRfc4122(),
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('v1 → v2', $output);
        self::assertStringContainsString('$name', $output);
    }

    #[Test]
    public function showsNoDifferencesWhenIdentical(): void
    {
        $schema = ApiSchemaFactory::createOne();
        $phpCode = "<?php\n\nclass IdenticalResponse {}";

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'phpCode' => $phpCode,
            'version' => 1,
            'isCurrent' => false,
        ]);
        $currentDto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'phpCode' => $phpCode,
            'version' => 2,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $currentDto->getId()->toRfc4122(),
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('No differences found', $output);
    }

    #[Test]
    public function comparesWithSpecificVersion(): void
    {
        $schema = ApiSchemaFactory::createOne();

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'phpCode' => "<?php\n\n// v1",
            'version' => 1,
            'isCurrent' => false,
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'phpCode' => "<?php\n\n// v2",
            'version' => 2,
            'isCurrent' => false,
        ]);
        $currentDto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'phpCode' => "<?php\n\n// v3",
            'version' => 3,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $currentDto->getId()->toRfc4122(),
            '--dto-version' => '1',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('v1 → v3', $output);
    }

    #[Test]
    public function comparesFromToVersions(): void
    {
        $schema = ApiSchemaFactory::createOne();

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'phpCode' => "<?php\n\n// version-1",
            'version' => 1,
            'isCurrent' => false,
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'phpCode' => "<?php\n\n// version-2",
            'version' => 2,
            'isCurrent' => false,
        ]);
        $currentDto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'phpCode' => "<?php\n\n// version-3",
            'version' => 3,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $currentDto->getId()->toRfc4122(),
            '--from' => '1',
            '--to' => '2',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('v1 → v2', $output);
        self::assertStringContainsString('version-1', $output);
        self::assertStringContainsString('version-2', $output);
    }

    #[Test]
    public function showsWarningWhenOnlyOneVersion(): void
    {
        $schema = ApiSchemaFactory::createOne();
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'version' => 1,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $dto->getId()->toRfc4122(),
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Only one version exists', $output);
    }

    #[Test]
    public function failsWhenDtoNotFound(): void
    {
        $this->commandTester->execute([
            'dto-id' => '01936f8a-1234-7abc-8def-0123456789ab',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('DTO not found', $output);
    }

    #[Test]
    public function failsWhenUuidIsInvalid(): void
    {
        $this->commandTester->execute([
            'dto-id' => 'not-a-uuid',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Invalid UUID format', $output);
    }

    #[Test]
    public function failsWhenVersionNotFound(): void
    {
        $schema = ApiSchemaFactory::createOne();

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'version' => 1,
            'isCurrent' => false,
        ]);
        $currentDto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'version' => 2,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $currentDto->getId()->toRfc4122(),
            '--dto-version' => '99',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Could not find version', $output);
    }

    #[Test]
    public function outputsRawDiff(): void
    {
        $schema = ApiSchemaFactory::createOne();

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'phpCode' => "<?php\n\nclass A {}",
            'version' => 1,
            'isCurrent' => false,
        ]);
        $currentDto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'phpCode' => "<?php\n\nclass B {}",
            'version' => 2,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $currentDto->getId()->toRfc4122(),
            '--raw' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringNotContainsString('DTO Diff:', $output);
    }

    #[Test]
    public function failsWhenFromVersionNotFound(): void
    {
        $schema = ApiSchemaFactory::createOne();

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'version' => 1,
            'isCurrent' => false,
        ]);
        $currentDto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'version' => 2,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $currentDto->getId()->toRfc4122(),
            '--from' => '99',
            '--to' => '2',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Version 99 not found', $output);
    }
}
