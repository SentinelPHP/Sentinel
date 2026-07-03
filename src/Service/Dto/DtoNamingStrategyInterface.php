<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\ValueObject\SchemaMetadata;

/**
 * Strategy interface for generating PHP class and property names from schemas.
 */
interface DtoNamingStrategyInterface
{
    /**
     * Generate a PHP class name from schema metadata.
     *
     * @param SchemaMetadata $metadata The schema metadata to generate a class name for
     * @return string The generated class name (e.g., "GetUsersResponse")
     */
    public function generateClassName(SchemaMetadata $metadata): string;

    /**
     * Generate a PHP property name from a JSON path or field name.
     *
     * @param string $jsonPath The JSON path or field name (e.g., "user_name", "data.items")
     * @return string The generated property name in camelCase (e.g., "userName", "dataItems")
     */
    public function generatePropertyName(string $jsonPath): string;

    /**
     * Generate a class name for a nested object.
     *
     * @param string $parentClassName The parent DTO class name (e.g., "UserResponse")
     * @param string $propertyName The property name containing the nested object (e.g., "address")
     * @return string The generated nested class name (e.g., "UserResponseAddress")
     */
    public function generateNestedClassName(string $parentClassName, string $propertyName): string;
}
