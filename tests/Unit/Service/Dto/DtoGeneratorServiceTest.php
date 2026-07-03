<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dto;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Exception\SchemaNotFoundException;
use App\Repository\ApiSchemaRepositoryInterface;
use App\Service\Dto\DefaultDtoNamingStrategy;
use App\Service\Dto\DtoGeneratorService;
use App\Service\Dto\DtoNamingStrategyInterface;
use App\Service\Dto\PhpClassBuilder;
use App\Service\Dto\PhpClassBuilderInterface;
use SentinelPHP\Dto\TypeMapper;
use SentinelPHP\Dto\TypeMapperInterface;
use App\ValueObject\DtoGeneratorConfig;
use App\ValueObject\GeneratedDto;
use SentinelPHP\Dto\MappedType;
use SentinelPHP\Dto\PropertyDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DtoGeneratorService::class)]
#[UsesClass(ApiSchema::class)]
#[UsesClass(ApiToken::class)]
#[UsesClass(SchemaType::class)]
#[UsesClass(DefaultDtoNamingStrategy::class)]
#[UsesClass(DtoGeneratorConfig::class)]
#[UsesClass(GeneratedDto::class)]
#[UsesClass(SchemaNotFoundException::class)]
#[UsesClass(TypeMapper::class)]
#[UsesClass(PhpClassBuilder::class)]
#[UsesClass(MappedType::class)]
#[UsesClass(PropertyDefinition::class)]
final class DtoGeneratorServiceTest extends TestCase
{
    private DtoNamingStrategyInterface $namingStrategy;
    private TypeMapperInterface $typeMapper;
    private PhpClassBuilderInterface $classBuilder;
    private DtoGeneratorConfig $config;

    protected function setUp(): void
    {
        $this->namingStrategy = new DefaultDtoNamingStrategy();
        $this->typeMapper = new TypeMapper('App\\Dto\\Generated\\Enum');
        $this->classBuilder = new PhpClassBuilder();
        $this->config = new DtoGeneratorConfig(
            defaultNamespace: 'App\\Dto\\Generated',
            outputDirectory: 'src/Dto/Generated',
            phpVersion: '8.2',
        );
    }

    private function createServiceWithStub(): DtoGeneratorService
    {
        /** @var ApiSchemaRepositoryInterface&Stub $schemaRepository */
        $schemaRepository = $this->createStub(ApiSchemaRepositoryInterface::class);

        return new DtoGeneratorService(
            $schemaRepository,
            $this->namingStrategy,
            $this->typeMapper,
            $this->classBuilder,
            $this->config,
        );
    }

    /**
     * @return array{DtoGeneratorService, ApiSchemaRepositoryInterface&MockObject}
     */
    private function createServiceWithMock(): array
    {
        /** @var ApiSchemaRepositoryInterface&MockObject $schemaRepository */
        $schemaRepository = $this->createMock(ApiSchemaRepositoryInterface::class);

        $service = new DtoGeneratorService(
            $schemaRepository,
            $this->namingStrategy,
            $this->typeMapper,
            $this->classBuilder,
            $this->config,
        );

        return [$service, $schemaRepository];
    }

    #[Test]
    public function itGeneratesDtoFromSchema(): void
    {
        $schema = $this->createSchema('/users', 'GET', SchemaType::Response, [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ],
            'required' => ['id', 'name'],
        ]);

        $service = $this->createServiceWithStub();
        $result = $service->generateFromSchema($schema);

        self::assertInstanceOf(GeneratedDto::class, $result);
        self::assertSame('GetUsersResponse', $result->className);
        self::assertSame('App\\Dto\\Generated', $result->namespace);
        self::assertSame($schema, $result->schema);
        self::assertInstanceOf(\DateTimeImmutable::class, $result->generatedAt);

        self::assertStringContainsString('namespace App\\Dto\\Generated;', $result->phpCode);
        self::assertStringContainsString('final readonly class GetUsersResponse', $result->phpCode);
        self::assertStringContainsString('public int $id,', $result->phpCode);
        self::assertStringContainsString('public string $name,', $result->phpCode);
        self::assertStringContainsString('public ?string $email = null,', $result->phpCode);
    }

    #[Test]
    public function itGeneratesDtoFromEndpoint(): void
    {
        $tokenId = Uuid::v7();
        $schema = $this->createSchema('/orders', 'POST', SchemaType::Response, [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'string'],
                'total' => ['type' => 'number'],
            ],
            'required' => ['order_id', 'total'],
        ]);

        [$service, $schemaRepository] = $this->createServiceWithMock();

        $schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->with($tokenId, 'api.example.com', '/orders', 'POST', SchemaType::Response)
            ->willReturn($schema);

        $result = $service->generateFromEndpoint(
            (string) $tokenId,
            'api.example.com',
            '/orders',
            'POST',
        );

        self::assertSame('PostOrdersResponse', $result->className);
        self::assertStringContainsString('public string $orderId,', $result->phpCode);
        self::assertStringContainsString('public float $total,', $result->phpCode);
    }

    #[Test]
    public function itThrowsExceptionWhenSchemaNotFound(): void
    {
        $tokenId = Uuid::v7();

        [$service, $schemaRepository] = $this->createServiceWithMock();

        $schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn(null);

        $this->expectException(SchemaNotFoundException::class);
        $this->expectExceptionMessage('No master schema found for endpoint');

        $service->generateFromEndpoint(
            (string) $tokenId,
            'api.example.com',
            '/unknown',
            'GET',
        );
    }

    #[Test]
    public function itGeneratesBatchOfDtos(): void
    {
        $schemas = [
            $this->createSchema('/users', 'GET', SchemaType::Response, [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ]),
            $this->createSchema('/orders', 'POST', SchemaType::Response, [
                'type' => 'object',
                'properties' => ['order_id' => ['type' => 'string']],
                'required' => ['order_id'],
            ]),
        ];

        $service = $this->createServiceWithStub();
        $results = $service->generateBatch($schemas);

        self::assertCount(2, $results);
        self::assertSame('GetUsersResponse', $results[0]->className);
        self::assertSame('PostOrdersResponse', $results[1]->className);
    }

    #[Test]
    public function itHandlesEmptySchema(): void
    {
        $schema = $this->createSchema('/empty', 'GET', SchemaType::Response, [
            'type' => 'object',
        ]);

        $service = $this->createServiceWithStub();
        $result = $service->generateFromSchema($schema);

        self::assertSame('GetEmptyResponse', $result->className);
        self::assertStringContainsString('final readonly class GetEmptyResponse', $result->phpCode);
        self::assertStringNotContainsString('__construct', $result->phpCode);
    }

    #[Test]
    public function itMapsJsonTypesToPhpTypes(): void
    {
        $schema = $this->createSchema('/types', 'GET', SchemaType::Response, [
            'type' => 'object',
            'properties' => [
                'string_field' => ['type' => 'string'],
                'int_field' => ['type' => 'integer'],
                'float_field' => ['type' => 'number'],
                'bool_field' => ['type' => 'boolean'],
                'array_field' => ['type' => 'array'],
                'object_field' => ['type' => 'object'],
            ],
            'required' => ['string_field', 'int_field', 'float_field', 'bool_field', 'array_field', 'object_field'],
        ]);

        $service = $this->createServiceWithStub();
        $result = $service->generateFromSchema($schema);

        self::assertStringContainsString('public string $stringField,', $result->phpCode);
        self::assertStringContainsString('public int $intField,', $result->phpCode);
        self::assertStringContainsString('public float $floatField,', $result->phpCode);
        self::assertStringContainsString('public bool $boolField,', $result->phpCode);
        self::assertStringContainsString('public array $arrayField,', $result->phpCode);
        self::assertStringContainsString('public array $objectField,', $result->phpCode);
    }

    #[Test]
    public function itIncludesDocblockWithSchemaInfo(): void
    {
        $schema = $this->createSchema('/users/{id}', 'GET', SchemaType::Response, [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ]);

        $service = $this->createServiceWithStub();
        $result = $service->generateFromSchema($schema);

        self::assertStringContainsString('Auto-generated DTO for GET /users/{id}', $result->phpCode);
        self::assertStringContainsString('@generated', $result->phpCode);
        self::assertStringContainsString('@see Schema version:', $result->phpCode);
    }

    /**
     * @param array<string, mixed> $jsonSchema
     */
    private function createSchema(string $path, string $method, SchemaType $type, array $jsonSchema): ApiSchema
    {
        $token = new ApiToken();
        $token->setName('test-token');
        $token->setAllowedTargets(['https://api.example.com']);

        $schema = new ApiSchema();
        $schema->setToken($token);
        $schema->setTargetHost('api.example.com');
        $schema->setEndpointPath($path);
        $schema->setHttpMethod($method);
        $schema->setSchemaType($type);
        $schema->setJsonSchema($jsonSchema);

        return $schema;
    }
}
