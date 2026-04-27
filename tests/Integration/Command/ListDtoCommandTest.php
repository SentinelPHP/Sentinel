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

final class ListDtoCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:dto:list');
        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function listsAllDtos(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Test Token']);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'GetUsersResponse',
            'namespace' => 'App\\Dto\\Generated',
            'version' => 1,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('GetUsersResponse', $output);
        self::assertStringContainsString('App\\Dto\\Generated', $output);
        self::assertStringContainsString('GET /users', $output);
        self::assertStringContainsString('Total: 1 DTO(s)', $output);
    }

    #[Test]
    public function showsInfoWhenNoDtosFound(): void
    {
        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('No generated DTOs found', $output);
    }

    #[Test]
    public function filtersDtosByTokenName(): void
    {
        $token1 = ApiTokenFactory::createOne(['name' => 'Token Alpha']);
        $token2 = ApiTokenFactory::createOne(['name' => 'Token Beta']);

        $schema1 = ApiSchemaFactory::createOne(['token' => $token1, 'endpointPath' => '/alpha']);
        $schema2 = ApiSchemaFactory::createOne(['token' => $token2, 'endpointPath' => '/beta']);

        GeneratedDtoFactory::createOne([
            'schema' => $schema1,
            'className' => 'AlphaResponse',
            'isCurrent' => true,
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema2,
            'className' => 'BetaResponse',
            'isCurrent' => true,
        ]);

        $this->commandTester->execute(['--token' => 'Token Alpha']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('AlphaResponse', $output);
        self::assertStringNotContainsString('BetaResponse', $output);
    }

    #[Test]
    public function filtersDtosByTokenUuid(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'UUID Token']);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'UuidResponse',
            'isCurrent' => true,
        ]);

        $otherSchema = ApiSchemaFactory::createOne();
        GeneratedDtoFactory::createOne([
            'schema' => $otherSchema,
            'className' => 'OtherResponse',
            'isCurrent' => true,
        ]);

        $this->commandTester->execute(['--token' => $token->getId()->toRfc4122()]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('UuidResponse', $output);
        self::assertStringNotContainsString('OtherResponse', $output);
    }

    #[Test]
    public function filtersDtosByEndpoint(): void
    {
        $schema1 = ApiSchemaFactory::createOne(['endpointPath' => '/users/profile']);
        $schema2 = ApiSchemaFactory::createOne(['endpointPath' => '/orders/list']);

        GeneratedDtoFactory::createOne([
            'schema' => $schema1,
            'className' => 'UsersProfileResponse',
            'isCurrent' => true,
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema2,
            'className' => 'OrdersListResponse',
            'isCurrent' => true,
        ]);

        $this->commandTester->execute(['--endpoint' => 'users']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('UsersProfileResponse', $output);
        self::assertStringNotContainsString('OrdersListResponse', $output);
    }

    #[Test]
    public function filtersDtosByClassName(): void
    {
        $schema1 = ApiSchemaFactory::createOne();
        $schema2 = ApiSchemaFactory::createOne();

        GeneratedDtoFactory::createOne([
            'schema' => $schema1,
            'className' => 'UserResponse',
            'isCurrent' => true,
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema2,
            'className' => 'OrderResponse',
            'isCurrent' => true,
        ]);

        $this->commandTester->execute(['--class' => 'User']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('UserResponse', $output);
        self::assertStringNotContainsString('OrderResponse', $output);
    }

    #[Test]
    public function respectsLimitOption(): void
    {
        $token = ApiTokenFactory::createOne();
        for ($i = 0; $i < 5; $i++) {
            $schema = ApiSchemaFactory::createOne(['token' => $token]);
            GeneratedDtoFactory::createOne([
                'schema' => $schema,
                'className' => "Response{$i}",
                'isCurrent' => true,
            ]);
        }

        $this->commandTester->execute(['--limit' => '2']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Showing 2 of 5 total DTOs', $output);
    }

    #[Test]
    public function showsVersionCount(): void
    {
        $schema = ApiSchemaFactory::createOne();

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'MultiVersionResponse',
            'version' => 1,
            'isCurrent' => false,
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'MultiVersionResponse',
            'version' => 2,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('MultiVersionResponse', $output);
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
    public function onlyShowsCurrentDtos(): void
    {
        $schema = ApiSchemaFactory::createOne();

        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'OldVersion',
            'version' => 1,
            'isCurrent' => false,
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'CurrentVersion',
            'version' => 2,
            'isCurrent' => true,
        ]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('CurrentVersion', $output);
        self::assertStringNotContainsString('OldVersion', $output);
        self::assertStringContainsString('Total: 1 DTO(s)', $output);
    }
}
