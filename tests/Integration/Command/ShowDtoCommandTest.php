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

final class ShowDtoCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:dto:show');
        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function showsDtoWithMetadata(): void
    {
        $schema = ApiSchemaFactory::createOne([
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
        ]);
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'GetUsersResponse',
            'namespace' => 'App\\Dto\\Generated',
            'phpCode' => "<?php\n\nclass GetUsersResponse {}",
            'version' => 1,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $dto->getId()->toRfc4122(),
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('App\\Dto\\Generated\\GetUsersResponse', $output);
        self::assertStringContainsString('GET /users', $output);
        self::assertStringContainsString('class GetUsersResponse', $output);
        self::assertStringContainsString('Version', $output);
    }

    #[Test]
    public function showsRawPhpCodeOnly(): void
    {
        $schema = ApiSchemaFactory::createOne();
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'RawResponse',
            'phpCode' => "<?php\n\nfinal class RawResponse {}",
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $dto->getId()->toRfc4122(),
            '--raw' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('final class RawResponse', $output);
        self::assertStringNotContainsString('Version', $output);
    }

    #[Test]
    public function showsSpecificVersion(): void
    {
        $schema = ApiSchemaFactory::createOne();

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'VersionedResponse',
            'phpCode' => "<?php\n\n// Version 1\nclass VersionedResponse {}",
            'version' => 1,
            'isCurrent' => false,
        ]);
        $currentDto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'VersionedResponse',
            'phpCode' => "<?php\n\n// Version 2\nclass VersionedResponse {}",
            'version' => 2,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $currentDto->getId()->toRfc4122(),
            '--dto-version' => '1',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('// Version 1', $output);
        self::assertStringNotContainsString('// Version 2', $output);
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
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'version' => 1,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $dto->getId()->toRfc4122(),
            '--dto-version' => '99',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Version 99 not found', $output);
    }

    #[Test]
    public function failsWhenVersionIsInvalid(): void
    {
        $schema = ApiSchemaFactory::createOne();
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $dto->getId()->toRfc4122(),
            '--dto-version' => '0',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Version must be a positive integer', $output);
    }

    #[Test]
    public function showsChecksumInMetadata(): void
    {
        $schema = ApiSchemaFactory::createOne();
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([
            'dto-id' => $dto->getId()->toRfc4122(),
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Checksum', $output);
        self::assertStringContainsString('...', $output);
    }
}
