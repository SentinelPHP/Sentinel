<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Entity\ApiSchema;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Exception\SchemaNotFoundException;
use App\Repository\ApiSchemaRepositoryInterface;
use App\ValueObject\DtoGenerationContext;
use App\ValueObject\DtoGeneratorConfig;
use App\ValueObject\GeneratedDto;
use App\ValueObject\SchemaMetadata;
use SentinelPHP\Dto\GeneratedEnum;
use SentinelPHP\Dto\MappedType;
use SentinelPHP\Dto\PropertyDefinition;
use SentinelPHP\Dto\TypeMapper;
use SentinelPHP\Dto\TypeMapperInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service for generating PHP DTOs from JSON Schemas.
 *
 * This is the core infrastructure service that coordinates DTO generation.
 * It delegates to naming strategies for class/property names and uses
 * TypeMapperService for type conversion and PhpClassBuilder for code generation.
 */
final class DtoGeneratorService implements DtoGeneratorServiceInterface
{
    /** @var list<GeneratedEnum> */
    private array $generatedEnums = [];

    private DtoGeneratorConfig $effectiveConfig;

    public function __construct(
        private readonly ApiSchemaRepositoryInterface $schemaRepository,
        private readonly DtoNamingStrategyInterface $namingStrategy,
        private readonly TypeMapperInterface $typeMapper,
        private readonly PhpClassBuilderInterface $classBuilder,
        private readonly DtoGeneratorConfig $config,
    ) {
        $this->effectiveConfig = $config;
    }

    public function generateFromSchema(ApiSchema $schema): GeneratedDto
    {
        // Merge token-level configuration with global config
        $this->effectiveConfig = $this->resolveEffectiveConfig($schema);

        $className = $this->namingStrategy->generateClassName(SchemaMetadata::fromSchema($schema));
        $namespace = $this->effectiveConfig->defaultNamespace;

        // Create context for tracking nested objects and circular references
        $context = new DtoGenerationContext();
        $jsonSchema = $schema->getJsonSchema();

        // Set root schema for $ref resolution
        if ($this->typeMapper instanceof TypeMapper) {
            $this->typeMapper->setRootSchema($jsonSchema);
        }

        try {
            $result = $this->buildDtoWithNested($schema, $className, $namespace, $jsonSchema, $context);
        } finally {
            if ($this->typeMapper instanceof TypeMapper) {
                $this->typeMapper->clearRootSchema();
            }
            // Reset to global config after generation
            $this->effectiveConfig = $this->config;
        }

        return $result;
    }

    /**
     * Resolve the effective configuration by merging global and token-level settings.
     */
    private function resolveEffectiveConfig(ApiSchema $schema): DtoGeneratorConfig
    {
        $token = $schema->getToken();
        if (!$token->hasDtoConfiguration()) {
            return $this->config;
        }

        return $token->getDtoConfiguration()->mergeIntoConfig($this->config);
    }

    public function generateFromEndpoint(string $tokenId, string $host, string $path, string $method): GeneratedDto
    {
        $schema = $this->schemaRepository->findMasterSchema(
            Uuid::fromString($tokenId),
            $host,
            $path,
            strtoupper($method),
            SchemaType::Response,
        );

        if ($schema === null) {
            throw SchemaNotFoundException::forEndpoint($tokenId, $host, $path, $method);
        }

        return $this->generateFromSchema($schema);
    }

    public function generateBatch(array $schemas): array
    {
        $results = [];

        foreach ($schemas as $schema) {
            $results[] = $this->generateFromSchema($schema);
        }

        return $results;
    }

    /**
     * Get the enums generated during the last generation call.
     *
     * @return list<GeneratedEnum>
     */
    public function getGeneratedEnums(): array
    {
        return $this->generatedEnums;
    }

    /**
     * Build a DTO with all its nested DTOs.
     *
     * @param array<string, mixed> $jsonSchema
     */
    private function buildDtoWithNested(
        ApiSchema $schema,
        string $className,
        string $namespace,
        array $jsonSchema,
        DtoGenerationContext $context,
    ): GeneratedDto {
        $this->generatedEnums = [];

        $schemaHash = DtoGenerationContext::computeSchemaHash($jsonSchema);

        // Check for circular reference
        if ($context->isProcessing($schemaHash)) {
            // Return a placeholder DTO for circular references
            return $this->buildCircularReferencePlaceholder($schema, $className, $namespace);
        }

        // Check if already generated
        $existing = $context->getGenerated($schemaHash);
        if ($existing !== null) {
            return $existing;
        }

        $context->pushProcessing($schemaHash);

        try {
            // Build the main DTO and collect nested schemas
            $nestedSchemas = [];
            $phpCode = $this->buildPhpCodeWithContext(
                $schema,
                $className,
                $namespace,
                $jsonSchema,
                $context,
                $nestedSchemas,
            );

            // Generate nested DTOs
            /** @var list<GeneratedDto> $nestedDtos */
            $nestedDtos = [];
            foreach ($nestedSchemas as $nestedInfo) {
                $nestedDto = $this->buildDtoWithNested(
                    $schema,
                    $nestedInfo['className'],
                    $namespace,
                    $nestedInfo['schema'],
                    $context,
                );
                $nestedDtos[] = $nestedDto;
            }

            $dto = new GeneratedDto(
                className: $className,
                namespace: $namespace,
                phpCode: $phpCode,
                schema: $schema,
                generatedAt: new \DateTimeImmutable(),
                nestedDtos: $nestedDtos,
            );

            $context->markGenerated($schemaHash, $dto);

            return $dto;
        } finally {
            $context->popProcessing($schemaHash);
        }
    }

    /**
     * Build PHP code and collect nested schemas for later generation.
     *
     * @param array<string, mixed> $jsonSchema
     * @param list<array{className: string, schema: array<string, mixed>, propertyName: string}> $nestedSchemas
     */
    private function buildPhpCodeWithContext(
        ApiSchema $schema,
        string $className,
        string $namespace,
        array $jsonSchema,
        DtoGenerationContext $context,
        array &$nestedSchemas,
    ): string {
        $properties = $this->extractProperties($jsonSchema);
        /** @var list<string> $requiredFields */
        $requiredFields = $jsonSchema['required'] ?? [];

        $this->classBuilder->reset()
            ->setNamespace($namespace)
            ->setClassName($className)
            ->setReadonly($this->effectiveConfig->readonlyProperties)
            ->setFinal(true)
            ->setGenerateGetters($this->effectiveConfig->generateGetters)
            ->setGenerateSerialization($this->effectiveConfig->generateSerialization)
            ->setGenerateJsonSerializable($this->effectiveConfig->generateJsonSerializable)
            ->setGenerateSerializerAttributes($this->effectiveConfig->generateSerializerAttributes)
            ->setGenerateValidation($this->effectiveConfig->generateValidation);

        // Apply template configuration
        if ($this->effectiveConfig->baseClass !== null) {
            $this->classBuilder->setBaseClass($this->effectiveConfig->baseClass);
        }
        foreach ($this->effectiveConfig->interfaces as $interface) {
            $this->classBuilder->addInterface($interface);
        }
        foreach ($this->effectiveConfig->traits as $trait) {
            $this->classBuilder->addTrait($trait);
        }

        // Build class docblock
        $docblock = $this->buildClassDocblock($schema);
        $this->classBuilder->setClassDocblock($docblock);

        // Add properties
        foreach ($properties as $name => $definition) {
            // Check if property should be excluded
            if ($this->effectiveConfig->isPropertyExcluded($name) || $this->effectiveConfig->isPropertyExcluded('$.' . $name)) {
                continue;
            }

            // Apply property name mapping from config, then fall back to naming strategy
            $propertyName = $this->effectiveConfig->getMappedPropertyName($name)
                ?? $this->namingStrategy->generatePropertyName($name);
            $isRequired = in_array($name, $requiredFields, true);

            /** @var array<string, mixed> $definition */
            $mappedType = $this->typeMapper->mapType(
                $definition,
                $isRequired,
                $propertyName,
                $className,
            );

            // Handle nested objects
            if ($mappedType->hasNestedSchema() && $mappedType->nestedSchema !== null) {
                $nestedClassName = $this->namingStrategy->generateNestedClassName($className, $propertyName);
                $nestedSchemaHash = DtoGenerationContext::computeSchemaHash($mappedType->nestedSchema);

                // Check for circular reference
                if ($context->isProcessing($nestedSchemaHash)) {
                    // Use array type for circular references
                    $mappedType = new MappedType(
                        nativeType: $isRequired ? 'array' : '?array',
                        docblockType: ($isRequired ? '' : '?') . "array<string, mixed> Circular reference to {$nestedClassName}",
                    );
                } else {
                    // Queue for generation and update type
                    $nestedSchemas[] = [
                        'className' => $nestedClassName,
                        'schema' => $mappedType->nestedSchema,
                        'propertyName' => $propertyName,
                    ];
                    $mappedType = $mappedType->withNestedClassName($nestedClassName, $namespace);
                }
            }

            // Track generated enums
            if ($mappedType->hasGeneratedEnum() && $mappedType->generatedEnum !== null) {
                $this->generatedEnums[] = $mappedType->generatedEnum;
            }

            // Extract description and format from schema
            /** @var string|null $description */
            $description = $definition['description'] ?? null;
            /** @var string|null $format */
            $format = $definition['format'] ?? null;

            // Build attributes
            $attributes = $this->buildPropertyAttributes($description, $format);

            // Get default value from schema
            $defaultValue = $definition['default'] ?? PropertyDefinition::NO_DEFAULT;

            // Track original JSON key if different from property name
            $jsonKey = $propertyName !== $name ? $name : null;

            $property = new PropertyDefinition(
                name: $propertyName,
                mappedType: $mappedType,
                isRequired: $isRequired,
                defaultValue: $defaultValue,
                description: $description,
                format: $format,
                attributes: $attributes,
                jsonKey: $jsonKey,
            );

            $this->classBuilder->addProperty($property);
        }

        return $this->classBuilder->build();
    }

    /**
     * Build a placeholder DTO for circular references.
     */
    private function buildCircularReferencePlaceholder(
        ApiSchema $schema,
        string $className,
        string $namespace,
    ): GeneratedDto {
        $this->classBuilder->reset()
            ->setNamespace($namespace)
            ->setClassName($className)
            ->setReadonly(true)
            ->setFinal(true);

        $this->classBuilder->setClassDocblock(
            "Circular reference placeholder for {$className}\n\n" .
            "@generated This file was automatically generated by SentinelPHP.\n" .
            "@see This class represents a circular reference in the schema."
        );

        return new GeneratedDto(
            className: $className,
            namespace: $namespace,
            phpCode: $this->classBuilder->build(),
            schema: $schema,
            generatedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Build the class-level docblock.
     */
    private function buildClassDocblock(ApiSchema $schema): string
    {
        $lines = [
            "Auto-generated DTO for {$schema->getHttpMethod()} {$schema->getEndpointPath()}",
            '',
            "@generated This file was automatically generated by SentinelPHP.",
            "@see Schema version: {$schema->getVersion()}",
        ];

        return implode("\n", $lines);
    }

    /**
     * Build PHP attributes for a property.
     *
     * @return list<string>
     */
    private function buildPropertyAttributes(?string $description, ?string $format): array
    {
        $attributes = [];

        if ($description !== null) {
            $escapedDescription = addslashes($description);
            $attributes[] = "\\SentinelPHP\\Dto\\Attribute\\Description('{$escapedDescription}')";
        }

        if ($format !== null) {
            $attributes[] = "\\SentinelPHP\\Dto\\Attribute\\Format('{$format}')";
        }

        return $attributes;
    }

    /**
     * Extract properties from a JSON Schema.
     *
     * @param array<string, mixed> $jsonSchema
     * @return array<string, array<string, mixed>>
     */
    private function extractProperties(array $jsonSchema): array
    {
        if (!isset($jsonSchema['properties']) || !is_array($jsonSchema['properties'])) {
            return [];
        }

        /** @var array<string, array<string, mixed>> */
        return $jsonSchema['properties'];
    }
}
