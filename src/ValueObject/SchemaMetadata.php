<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Entity\ApiSchema;
use SentinelPHP\Dto\Enum\SchemaType;

/**
 * Value object containing schema metadata for DTO naming.
 *
 * This decouples the naming strategy from the ApiSchema entity,
 * allowing the DTO generation logic to be extracted into a standalone library.
 */
final readonly class SchemaMetadata
{
    public function __construct(
        public string $httpMethod,
        public string $endpointPath,
        public SchemaType $schemaType,
        public ?string $targetHost = null,
    ) {
    }

    /**
     * Create SchemaMetadata from an ApiSchema entity.
     */
    public static function fromSchema(ApiSchema $schema): self
    {
        return new self(
            httpMethod: $schema->getHttpMethod(),
            endpointPath: $schema->getEndpointPath(),
            schemaType: $schema->getSchemaType(),
            targetHost: $schema->getTargetHost(),
        );
    }
}
