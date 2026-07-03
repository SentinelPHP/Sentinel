<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiSchema;
use SentinelPHP\Dto\Enum\SchemaType;
use Symfony\Component\Uid\Uuid;

interface ApiSchemaRepositoryInterface
{
    public function findMasterSchema(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): ?ApiSchema;

    /**
     * Find all master schemas for an endpoint (should normally be 0 or 1, but handles data integrity issues).
     *
     * @return list<ApiSchema>
     */
    public function findMasterSchemas(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): array;

    /**
     * @return list<ApiSchema>
     */
    public function findAllVersions(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): array;

    /**
     * Find the latest learned (non-master) schema for an endpoint.
     */
    public function findLatestLearned(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): ?ApiSchema;

    /**
     * Invalidate the cached master schema for an endpoint.
     */
    public function invalidateMasterSchema(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): void;
}
