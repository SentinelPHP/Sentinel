<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Entity\ApiSchema;
use App\Entity\GeneratedDto as GeneratedDtoEntity;
use App\ValueObject\GeneratedDto;

/**
 * Interface for DTO storage and versioning operations.
 */
interface DtoStorageServiceInterface
{
    /**
     * Store a generated DTO, handling versioning automatically.
     *
     * Returns null if the DTO is identical to the current version (no changes).
     */
    public function store(GeneratedDto $dto): ?GeneratedDtoEntity;

    /**
     * Store multiple generated DTOs (including nested DTOs).
     *
     * @return list<GeneratedDtoEntity> The stored entities (excludes unchanged DTOs)
     */
    public function storeAll(GeneratedDto $dto): array;

    /**
     * Find the current DTO for a schema.
     */
    public function findCurrent(ApiSchema $schema): ?GeneratedDtoEntity;

    /**
     * Find all versions of a DTO for a schema.
     *
     * @return list<GeneratedDtoEntity>
     */
    public function findAllVersions(ApiSchema $schema): array;

    /**
     * Find a specific version of a DTO.
     */
    public function findVersion(ApiSchema $schema, int $version): ?GeneratedDtoEntity;

    /**
     * Check if regeneration would produce different code.
     */
    public function hasChanges(GeneratedDto $dto): bool;

    /**
     * Rollback to a previous version.
     */
    public function rollbackToVersion(ApiSchema $schema, int $version): ?GeneratedDtoEntity;
}
