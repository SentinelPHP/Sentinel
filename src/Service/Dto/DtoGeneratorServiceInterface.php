<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Entity\ApiSchema;
use App\ValueObject\GeneratedDto;

/**
 * Service interface for generating PHP DTOs from JSON Schemas.
 */
interface DtoGeneratorServiceInterface
{
    /**
     * Generate a PHP DTO from an API schema.
     *
     * @param ApiSchema $schema The schema to generate a DTO from
     * @return GeneratedDto The generated DTO containing PHP code
     */
    public function generateFromSchema(ApiSchema $schema): GeneratedDto;

    /**
     * Generate a PHP DTO by looking up the schema for an endpoint.
     *
     * @param string $tokenId The API token ID
     * @param string $host The target host
     * @param string $path The endpoint path
     * @param string $method The HTTP method
     * @return GeneratedDto The generated DTO containing PHP code
     *
     * @throws \App\Exception\SchemaNotFoundException If no master schema exists for the endpoint
     */
    public function generateFromEndpoint(string $tokenId, string $host, string $path, string $method): GeneratedDto;

    /**
     * Generate PHP DTOs from multiple schemas.
     *
     * @param array<ApiSchema> $schemas The schemas to generate DTOs from
     * @return array<GeneratedDto> The generated DTOs
     */
    public function generateBatch(array $schemas): array;
}
