<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\ApiSchema;
use App\Entity\GeneratedDto;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Repository\GeneratedDtoRepository;
use App\Service\Dto\DtoExporterServiceInterface;
use App\Service\Dto\DtoGeneratorServiceInterface;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\ValueObject\ExportOptions;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration tests for the full DTO generation cycle:
 * Schema → DTO generation → Database storage → File export
 */
final class DtoGenerationCycleTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private DtoGeneratorServiceInterface $generator;
    private DtoExporterServiceInterface $exporter;
    private GeneratedDtoRepository $dtoRepository;
    private EntityManagerInterface $entityManager;
    private Filesystem $filesystem;
    private string $testOutputDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var DtoGeneratorServiceInterface $generator */
        $generator = $container->get(DtoGeneratorServiceInterface::class);
        $this->generator = $generator;

        /** @var DtoExporterServiceInterface $exporter */
        $exporter = $container->get(DtoExporterServiceInterface::class);
        $this->exporter = $exporter;

        /** @var GeneratedDtoRepository $dtoRepository */
        $dtoRepository = $container->get(GeneratedDtoRepository::class);
        $this->dtoRepository = $dtoRepository;

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        $this->filesystem = new Filesystem();
        $this->testOutputDir = sys_get_temp_dir() . '/sentinel_dto_cycle_test_' . bin2hex(random_bytes(4));
        $this->filesystem->mkdir($this->testOutputDir);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->testOutputDir)) {
            $this->filesystem->remove($this->testOutputDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function fullCycleFromSchemaToExportedFile(): void
    {
        // 1. Create schema
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'isMaster' => true,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                ],
                'required' => ['id', 'name'],
            ],
        ]);

        // 2. Generate DTO
        $schemaEntity = $this->entityManager->getRepository(ApiSchema::class)->find($schema->getId());
        self::assertNotNull($schemaEntity);

        $generatedDto = $this->generator->generateFromSchema($schemaEntity);

        self::assertSame('GetUsersResponse', $generatedDto->className);
        self::assertStringContainsString('public int $id,', $generatedDto->phpCode);
        self::assertStringContainsString('public string $name,', $generatedDto->phpCode);
        self::assertStringContainsString('public ?string $email = null,', $generatedDto->phpCode);

        // 3. Store in database
        $dtoEntity = new GeneratedDto();
        $dtoEntity->setSchema($schemaEntity);
        $dtoEntity->setClassName($generatedDto->className);
        $dtoEntity->setNamespace($generatedDto->namespace);
        $dtoEntity->setPhpCode($generatedDto->phpCode);
        $dtoEntity->setVersion(1);
        $dtoEntity->setIsCurrent(true);

        $this->entityManager->persist($dtoEntity);
        $this->entityManager->flush();

        // 4. Verify database storage
        $storedDto = $this->dtoRepository->findCurrentBySchema($schemaEntity);
        self::assertNotNull($storedDto);
        self::assertSame('GetUsersResponse', $storedDto->getClassName());
        self::assertSame(1, $storedDto->getVersion());
        self::assertTrue($storedDto->isCurrent());
        self::assertNotEmpty($storedDto->getChecksum());

        // 5. Export to file
        $exportResult = $this->exporter->exportDto($storedDto, $this->testOutputDir);

        self::assertTrue($exportResult->isSuccess());
        self::assertCount(1, $exportResult->filesWritten);
        self::assertFileExists($exportResult->filesWritten[0]);

        // 6. Verify exported file content
        $fileContent = file_get_contents($exportResult->filesWritten[0]);
        self::assertIsString($fileContent);
        self::assertStringContainsString('<?php', $fileContent);
        self::assertStringContainsString('final readonly class GetUsersResponse', $fileContent);
        self::assertStringContainsString('AUTO-GENERATED FILE', $fileContent);
    }

    #[Test]
    public function checksumCalculationAndVersionTracking(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
            ],
        ]);

        $schemaEntity = $this->entityManager->getRepository(ApiSchema::class)->find($schema->getId());
        self::assertNotNull($schemaEntity);

        // Generate first version
        $generatedDto1 = $this->generator->generateFromSchema($schemaEntity);

        $dtoEntity1 = new GeneratedDto();
        $dtoEntity1->setSchema($schemaEntity);
        $dtoEntity1->setClassName($generatedDto1->className);
        $dtoEntity1->setNamespace($generatedDto1->namespace);
        $dtoEntity1->setPhpCode($generatedDto1->phpCode);
        $dtoEntity1->setVersion(1);
        $dtoEntity1->setIsCurrent(true);

        $this->entityManager->persist($dtoEntity1);
        $this->entityManager->flush();

        $checksum1 = $dtoEntity1->getChecksum();
        self::assertNotEmpty($checksum1);

        // Generate again with same schema - checksum should match
        $generatedDto2 = $this->generator->generateFromSchema($schemaEntity);

        $dtoEntity2 = new GeneratedDto();
        $dtoEntity2->setSchema($schemaEntity);
        $dtoEntity2->setClassName($generatedDto2->className);
        $dtoEntity2->setNamespace($generatedDto2->namespace);
        $dtoEntity2->setPhpCode($generatedDto2->phpCode);
        $dtoEntity2->setVersion(2);
        $dtoEntity2->setIsCurrent(false);

        $this->entityManager->persist($dtoEntity2);
        $this->entityManager->flush();

        $checksum2 = $dtoEntity2->getChecksum();

        // Checksums should be identical for same code
        self::assertSame($checksum1, $checksum2);
    }

    #[Test]
    public function regenerationDetectionSkipsIdenticalCode(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'string'],
                ],
                'required' => ['value'],
            ],
        ]);

        $schemaEntity = $this->entityManager->getRepository(ApiSchema::class)->find($schema->getId());
        self::assertNotNull($schemaEntity);

        // Generate and store first version
        $generatedDto = $this->generator->generateFromSchema($schemaEntity);

        $dtoEntity = new GeneratedDto();
        $dtoEntity->setSchema($schemaEntity);
        $dtoEntity->setClassName($generatedDto->className);
        $dtoEntity->setNamespace($generatedDto->namespace);
        $dtoEntity->setPhpCode($generatedDto->phpCode);
        $dtoEntity->setVersion(1);
        $dtoEntity->setIsCurrent(true);

        $this->entityManager->persist($dtoEntity);
        $this->entityManager->flush();

        $originalChecksum = $dtoEntity->getChecksum();

        // Regenerate - should produce same checksum
        $regeneratedDto = $this->generator->generateFromSchema($schemaEntity);

        $tempEntity = new GeneratedDto();
        $tempEntity->setSchema($schemaEntity);
        $tempEntity->setClassName($regeneratedDto->className);
        $tempEntity->setNamespace($regeneratedDto->namespace);
        $tempEntity->setPhpCode($regeneratedDto->phpCode);
        $tempEntity->setVersion(2);
        $tempEntity->setIsCurrent(false);

        // Compare checksums without persisting
        $newChecksum = $tempEntity->getChecksum();

        self::assertSame($originalChecksum, $newChecksum, 'Identical code should produce identical checksum');
    }

    #[Test]
    public function batchGenerationWithMultipleSchemas(): void
    {
        $token = ApiTokenFactory::createOne();

        $schemas = [];
        for ($i = 1; $i <= 3; $i++) {
            $schemas[] = ApiSchemaFactory::createOne([
                'token' => $token,
                'endpointPath' => "/endpoint{$i}",
                'httpMethod' => 'GET',
                'schemaType' => SchemaType::Response,
                'jsonSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'value' . $i => ['type' => 'string'],
                    ],
                    'required' => ['id'],
                ],
            ]);
        }

        $schemaEntities = [];
        foreach ($schemas as $schema) {
            $entity = $this->entityManager->getRepository(ApiSchema::class)->find($schema->getId());
            self::assertNotNull($entity);
            $schemaEntities[] = $entity;
        }

        // Batch generate
        $results = $this->generator->generateBatch($schemaEntities);

        self::assertCount(3, $results);

        foreach ($results as $index => $result) {
            self::assertStringContainsString('Endpoint' . ($index + 1), $result->className);
            self::assertStringContainsString('public int $id,', $result->phpCode);
        }
    }

    #[Test]
    public function nestedObjectsGenerateMultipleDtos(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'endpointPath' => '/orders',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'customer' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'email' => ['type' => 'string'],
                        ],
                        'required' => ['name'],
                    ],
                ],
                'required' => ['id', 'customer'],
            ],
        ]);

        $schemaEntity = $this->entityManager->getRepository(ApiSchema::class)->find($schema->getId());
        self::assertNotNull($schemaEntity);

        $generatedDto = $this->generator->generateFromSchema($schemaEntity);

        self::assertTrue($generatedDto->hasNestedDtos());
        self::assertCount(1, $generatedDto->nestedDtos);

        $allDtos = $generatedDto->getAllDtos();
        self::assertCount(2, $allDtos);

        $classNames = array_map(fn($dto) => $dto->className, $allDtos);
        self::assertContains('GetOrdersResponse', $classNames);
        self::assertContains('GetOrdersResponseCustomer', $classNames);
    }

    #[Test]
    public function exportAllCurrentDtos(): void
    {
        $token = ApiTokenFactory::createOne();

        // Create multiple schemas and DTOs
        for ($i = 1; $i <= 2; $i++) {
            $schema = ApiSchemaFactory::createOne([
                'token' => $token,
                'endpointPath' => "/resource{$i}",
                'httpMethod' => 'GET',
                'schemaType' => SchemaType::Response,
                'jsonSchema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
            ]);

            $schemaEntity = $this->entityManager->getRepository(ApiSchema::class)->find($schema->getId());
            self::assertNotNull($schemaEntity);

            $generatedDto = $this->generator->generateFromSchema($schemaEntity);

            $dtoEntity = new GeneratedDto();
            $dtoEntity->setSchema($schemaEntity);
            $dtoEntity->setClassName($generatedDto->className);
            $dtoEntity->setNamespace($generatedDto->namespace);
            $dtoEntity->setPhpCode($generatedDto->phpCode);
            $dtoEntity->setVersion(1);
            $dtoEntity->setIsCurrent(true);

            $this->entityManager->persist($dtoEntity);
        }

        $this->entityManager->flush();

        // Export all
        $exportResult = $this->exporter->exportAll($this->testOutputDir);

        self::assertTrue($exportResult->isSuccess());
        self::assertCount(2, $exportResult->filesWritten);

        foreach ($exportResult->filesWritten as $filePath) {
            self::assertFileExists($filePath);
        }
    }
}
