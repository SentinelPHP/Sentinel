<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Entity\ApiSchema;
use App\Entity\GeneratedDto as GeneratedDtoEntity;
use App\Repository\GeneratedDtoRepository;
use App\ValueObject\GeneratedDto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for storing and versioning generated DTOs.
 */
final class DtoStorageService implements DtoStorageServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GeneratedDtoRepository $repository,
    ) {
    }

    public function store(GeneratedDto $dto): ?GeneratedDtoEntity
    {
        $schema = $dto->schema;
        $newChecksum = GeneratedDtoEntity::computeChecksum($dto->phpCode);

        // Check if identical to current version
        $currentChecksum = $this->repository->findCurrentChecksum($schema);
        if ($currentChecksum === $newChecksum) {
            return null;
        }

        // Clear current flag on existing versions
        $this->repository->clearCurrentFlag($schema);

        // Get next version number
        $nextVersion = $this->repository->getNextVersion($schema);

        // Create new entity
        $entity = new GeneratedDtoEntity();
        $entity->setSchema($schema)
            ->setClassName($dto->className)
            ->setNamespace($dto->namespace)
            ->setPhpCode($dto->phpCode)
            ->setVersion($nextVersion)
            ->setIsCurrent(true);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity;
    }

    public function storeAll(GeneratedDto $dto): array
    {
        $stored = [];
        $allDtos = $dto->getAllDtos();

        foreach ($allDtos as $singleDto) {
            $entity = $this->store($singleDto);
            if ($entity !== null) {
                $stored[] = $entity;
            }
        }

        return $stored;
    }

    public function findCurrent(ApiSchema $schema): ?GeneratedDtoEntity
    {
        return $this->repository->findCurrentBySchema($schema);
    }

    public function findAllVersions(ApiSchema $schema): array
    {
        return $this->repository->findAllVersions($schema);
    }

    public function findVersion(ApiSchema $schema, int $version): ?GeneratedDtoEntity
    {
        return $this->repository->findBySchemaAndVersion($schema, $version);
    }

    public function hasChanges(GeneratedDto $dto): bool
    {
        $newChecksum = GeneratedDtoEntity::computeChecksum($dto->phpCode);
        $currentChecksum = $this->repository->findCurrentChecksum($dto->schema);

        return $currentChecksum !== $newChecksum;
    }

    public function rollbackToVersion(ApiSchema $schema, int $version): ?GeneratedDtoEntity
    {
        $targetVersion = $this->repository->findBySchemaAndVersion($schema, $version);
        if ($targetVersion === null) {
            return null;
        }

        // Clear current flag on all versions
        $this->repository->clearCurrentFlag($schema);

        // Set target version as current
        $targetVersion->setIsCurrent(true);
        $this->entityManager->flush();

        return $targetVersion;
    }
}
