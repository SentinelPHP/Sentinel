<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\GeneratedDto as GeneratedDtoEntity;
use App\Event\DtoGeneratedEvent;
use App\Message\GenerateDtoMessage;
use App\Repository\ApiSchemaRepository;
use App\Repository\GeneratedDtoRepository;
use App\Service\Dto\DtoGeneratorServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsMessageHandler]
final readonly class GenerateDtoMessageHandler
{
    public function __construct(
        private ApiSchemaRepository $schemaRepository,
        private GeneratedDtoRepository $dtoRepository,
        private DtoGeneratorServiceInterface $dtoGenerator,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateDtoMessage $message): void
    {
        $schema = $this->schemaRepository->find(Uuid::fromString($message->schemaId));

        if ($schema === null) {
            $this->logger->warning('Schema not found for DTO generation', [
                'schema_id' => $message->schemaId,
            ]);

            return;
        }

        $this->logger->info('Starting DTO generation', [
            'schema_id' => $message->schemaId,
            'endpoint' => sprintf('%s %s', $schema->getHttpMethod(), $schema->getEndpointPath()),
        ]);

        try {
            $generatedDto = $this->dtoGenerator->generateFromSchema($schema);

            // Check if the code has changed
            $currentChecksum = $this->dtoRepository->findCurrentChecksum($schema);
            $newChecksum = GeneratedDtoEntity::computeChecksum($generatedDto->phpCode);

            if ($currentChecksum === $newChecksum) {
                $this->logger->info('DTO unchanged, skipping storage', [
                    'schema_id' => $message->schemaId,
                    'class_name' => $generatedDto->className,
                ]);

                return;
            }

            // Clear current flag on existing DTOs
            $this->dtoRepository->clearCurrentFlag($schema);

            // Create new DTO entity
            $dtoEntity = new GeneratedDtoEntity();
            $dtoEntity->setSchema($schema);
            $dtoEntity->setClassName($generatedDto->className);
            $dtoEntity->setNamespace($generatedDto->namespace);
            $dtoEntity->setPhpCode($generatedDto->phpCode);
            $dtoEntity->setVersion($this->dtoRepository->getNextVersion($schema));
            $dtoEntity->setIsCurrent(true);

            $this->entityManager->persist($dtoEntity);
            $this->entityManager->flush();

            $this->logger->info('DTO generated successfully', [
                'schema_id' => $message->schemaId,
                'dto_id' => $dtoEntity->getId()->toRfc4122(),
                'class_name' => $generatedDto->className,
                'version' => $dtoEntity->getVersion(),
            ]);

            // Dispatch event for notifications
            $this->eventDispatcher->dispatch(new DtoGeneratedEvent($dtoEntity));
        } catch (\Throwable $e) {
            $this->logger->error('DTO generation failed', [
                'schema_id' => $message->schemaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
