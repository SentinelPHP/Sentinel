<?php

declare(strict_types=1);

namespace SentinelPHP\Dto;

interface TypeMapperInterface
{
    /**
     * Map a JSON Schema type definition to a PHP type.
     *
     * @param array<string, mixed> $definition The JSON Schema type definition
     * @param bool $isRequired Whether the property is required
     * @param string|null $propertyName The property name (for enum naming)
     * @param string|null $parentClassName The parent class name (for enum naming)
     * @return MappedType The mapped PHP type information
     */
    public function mapType(
        array $definition,
        bool $isRequired = true,
        ?string $propertyName = null,
        ?string $parentClassName = null,
    ): MappedType;

    /**
     * Check if a definition represents an enum type.
     *
     * @param array<string, mixed> $definition
     */
    public function isEnum(array $definition): bool;

    /**
     * Check if a definition represents a nested object.
     *
     * @param array<string, mixed> $definition
     */
    public function isNestedObject(array $definition): bool;

    /**
     * Set the root schema for $ref resolution.
     *
     * @param array<string, mixed> $rootSchema
     */
    public function setRootSchema(array $rootSchema): void;

    /**
     * Clear the root schema.
     */
    public function clearRootSchema(): void;
}
