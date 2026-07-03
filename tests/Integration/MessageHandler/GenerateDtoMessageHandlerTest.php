<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\GeneratedDto as GeneratedDtoEntity;
use App\Enum\DtoGenerationStatus;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Message\GenerateDtoMessage;
use App\MessageHandler\GenerateDtoMessageHandler;
use App\Repository\GeneratedDtoRepository;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class GenerateDtoMessageHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private GenerateDtoMessageHandler $handler;
    private GeneratedDtoRepository $dtoRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->handler = self::getContainer()->get(GenerateDtoMessageHandler::class);
        $this->dtoRepository = self::getContainer()->get(GeneratedDtoRepository::class);
    }

    #[Test]
    public function handlerGeneratesDtoFromSchema(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'isMaster' => true,
            'schemaType' => SchemaType::Response,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
                'required' => ['id', 'name'],
            ],
        ]);

        $message = new GenerateDtoMessage($schema->getId()->toRfc4122());

        ($this->handler)($message);

        $schemaEntity = self::getContainer()->get('doctrine')->getRepository(\App\Entity\ApiSchema::class)->find($schema->getId());
        self::assertNotNull($schemaEntity);
        $dto = $this->dtoRepository->findCurrentBySchema($schemaEntity);
        self::assertNotNull($dto);
        self::assertStringContainsString('class', $dto->getPhpCode());
        self::assertStringContainsString('int $id', $dto->getPhpCode());
        self::assertStringContainsString('string $name', $dto->getPhpCode());
        self::assertEquals(DtoGenerationStatus::Completed, $dto->getStatus());
        self::assertTrue($dto->isCurrent());
        self::assertEquals(1, $dto->getVersion());
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function handlerSkipsNonExistentSchema(): void
    {
        $nonExistentId = Uuid::v7()->toRfc4122();
        $message = new GenerateDtoMessage($nonExistentId);

        ($this->handler)($message);
    }

    #[Test]
    public function handlerSkipsIdenticalGeneration(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'isMaster' => true,
            'schemaType' => SchemaType::Response,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ],
        ]);

        $message = new GenerateDtoMessage($schema->getId()->toRfc4122());

        // First generation
        ($this->handler)($message);

        $schemaEntity = self::getContainer()->get('doctrine')->getRepository(\App\Entity\ApiSchema::class)->find($schema->getId());
        self::assertNotNull($schemaEntity);
        $firstDto = $this->dtoRepository->findCurrentBySchema($schemaEntity);
        self::assertNotNull($firstDto);
        $firstVersion = $firstDto->getVersion();

        // Second generation with same schema - should be skipped
        ($this->handler)($message);

        $dtos = $this->dtoRepository->findAllVersions($schemaEntity);
        self::assertCount(1, $dtos);
        self::assertEquals($firstVersion, $dtos[0]->getVersion());
    }

    #[Test]
    public function handlerCreatesNewVersionOnSchemaChange(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'isMaster' => true,
            'schemaType' => SchemaType::Response,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ],
        ]);

        $message = new GenerateDtoMessage($schema->getId()->toRfc4122());

        // First generation
        ($this->handler)($message);

        // Modify schema using entity manager
        $em = self::getContainer()->get('doctrine')->getManager();
        $schemaEntity = $em->getRepository(\App\Entity\ApiSchema::class)->find($schema->getId());
        self::assertNotNull($schemaEntity);
        $schemaEntity->setJsonSchema([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'email' => ['type' => 'string', 'format' => 'email'],
            ],
        ]);
        $em->flush();

        // Second generation with modified schema
        ($this->handler)($message);

        $dtos = $this->dtoRepository->findAllVersions($schemaEntity);
        self::assertCount(2, $dtos);

        $currentDto = $this->dtoRepository->findCurrentBySchema($schemaEntity);
        self::assertNotNull($currentDto);
        self::assertEquals(2, $currentDto->getVersion());
        self::assertTrue($currentDto->isCurrent());
        self::assertStringContainsString('email', $currentDto->getPhpCode());
    }
}
