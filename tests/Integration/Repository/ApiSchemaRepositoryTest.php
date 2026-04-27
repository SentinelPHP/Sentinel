<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\ApiToken;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Repository\ApiSchemaRepository;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(ApiSchemaRepository::class)]
final class ApiSchemaRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private ApiSchemaRepository $repository;
    private ApiToken $token;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var ApiSchemaRepository $repository */
        $repository = self::getContainer()->get(ApiSchemaRepository::class);
        $this->repository = $repository;
        $this->token = ApiTokenFactory::new()->create();
    }

    #[Test]
    public function findMasterSchemaReturnsNullWhenNoSchemasExist(): void
    {
        $result = $this->repository->findMasterSchema(
            $this->token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );

        self::assertNull($result);
    }

    #[Test]
    public function findMasterSchemaReturnsNullWhenNoMasterExists(): void
    {
        ApiSchemaFactory::new()->create([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => false,
        ]);

        $result = $this->repository->findMasterSchema(
            $this->token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );

        self::assertNull($result);
    }

    #[Test]
    public function findMasterSchemaReturnsMasterSchema(): void
    {
        $masterSchema = ApiSchemaFactory::new()->create([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => true,
        ]);

        $result = $this->repository->findMasterSchema(
            $this->token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );

        self::assertNotNull($result);
        self::assertEquals($masterSchema->getId(), $result->getId());
    }

    #[Test]
    public function findMasterSchemaMatchesAllCriteria(): void
    {
        ApiSchemaFactory::new()->create([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => true,
        ]);

        $resultDifferentHost = $this->repository->findMasterSchema(
            $this->token->getId(),
            'other.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );
        self::assertNull($resultDifferentHost);

        $resultDifferentPath = $this->repository->findMasterSchema(
            $this->token->getId(),
            'api.example.com',
            '/posts',
            'GET',
            SchemaType::Response,
        );
        self::assertNull($resultDifferentPath);

        $resultDifferentMethod = $this->repository->findMasterSchema(
            $this->token->getId(),
            'api.example.com',
            '/users',
            'POST',
            SchemaType::Response,
        );
        self::assertNull($resultDifferentMethod);

        $resultDifferentType = $this->repository->findMasterSchema(
            $this->token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Request,
        );
        self::assertNull($resultDifferentType);

        $otherToken = ApiTokenFactory::new()->create();
        $resultDifferentToken = $this->repository->findMasterSchema(
            $otherToken->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );
        self::assertNull($resultDifferentToken);
    }

    #[Test]
    public function findMasterSchemaNormalizesHttpMethod(): void
    {
        ApiSchemaFactory::new()->create([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => true,
        ]);

        $result = $this->repository->findMasterSchema(
            $this->token->getId(),
            'api.example.com',
            '/users',
            'get',
            SchemaType::Response,
        );

        self::assertNotNull($result);
    }

    #[Test]
    public function findAllVersionsReturnsEmptyArrayWhenNoSchemasExist(): void
    {
        $result = $this->repository->findAllVersions(
            $this->token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function findAllVersionsReturnsAllVersionsOrderedDescending(): void
    {
        ApiSchemaFactory::new()->create([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 1,
        ]);

        ApiSchemaFactory::new()->create([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 3,
        ]);

        ApiSchemaFactory::new()->create([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 2,
        ]);

        $result = $this->repository->findAllVersions(
            $this->token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );

        self::assertCount(3, $result);
        self::assertSame(3, $result[0]->getVersion());
        self::assertSame(2, $result[1]->getVersion());
        self::assertSame(1, $result[2]->getVersion());
    }

    #[Test]
    public function findAllVersionsOnlyReturnsMatchingSchemas(): void
    {
        ApiSchemaFactory::new()->create([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 1,
        ]);

        ApiSchemaFactory::new()->create([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/posts',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'version' => 1,
        ]);

        ApiSchemaFactory::new()->create([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'POST',
            'schemaType' => SchemaType::Response,
            'version' => 1,
        ]);

        $result = $this->repository->findAllVersions(
            $this->token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response,
        );

        self::assertCount(1, $result);
        self::assertSame('/users', $result[0]->getEndpointPath());
        self::assertSame('GET', $result[0]->getHttpMethod());
    }

    #[Test]
    public function findAllVersionsNormalizesHttpMethod(): void
    {
        ApiSchemaFactory::new()->create([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'POST',
            'schemaType' => SchemaType::Response,
            'version' => 1,
        ]);

        $result = $this->repository->findAllVersions(
            $this->token->getId(),
            'api.example.com',
            '/users',
            'post',
            SchemaType::Response,
        );

        self::assertCount(1, $result);
    }
}
