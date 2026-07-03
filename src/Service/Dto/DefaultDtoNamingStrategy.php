<?php

declare(strict_types=1);

namespace App\Service\Dto;

use SentinelPHP\Dto\Enum\SchemaType;
use App\ValueObject\SchemaMetadata;

/**
 * Default implementation of DTO naming strategy.
 *
 * Converts endpoint paths and HTTP methods to PascalCase class names,
 * and JSON field names to camelCase property names.
 */
final class DefaultDtoNamingStrategy implements DtoNamingStrategyInterface
{
    public function generateClassName(SchemaMetadata $metadata): string
    {
        $method = ucfirst(strtolower($metadata->httpMethod));
        $pathPart = $this->pathToPascalCase($metadata->endpointPath);
        $suffix = $this->getSuffix($metadata->schemaType);

        return $method . $pathPart . $suffix;
    }

    public function generatePropertyName(string $jsonPath): string
    {
        $sanitized = $this->sanitizeIdentifier($jsonPath);

        $parts = preg_split('/[_\-.\[\]]+/', $sanitized, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return 'property';
        }

        $result = strtolower($parts[0]);
        for ($i = 1, $count = count($parts); $i < $count; $i++) {
            $result .= ucfirst(strtolower($parts[$i]));
        }

        return $this->ensureValidPropertyName($result);
    }

    public function generateNestedClassName(string $parentClassName, string $propertyName): string
    {
        // Convert property name to PascalCase (handle snake_case, kebab-case, etc.)
        $parts = preg_split('/[_\-.\[\]]+/', $propertyName, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return $parentClassName . 'Nested';
        }

        $pascalProperty = '';
        foreach ($parts as $part) {
            $sanitized = $this->sanitizeIdentifier($part);
            if ($sanitized !== '') {
                $pascalProperty .= ucfirst(strtolower($sanitized));
            }
        }

        return $parentClassName . ($pascalProperty !== '' ? $pascalProperty : 'Nested');
    }

    /**
     * Convert an endpoint path to PascalCase.
     *
     * Examples:
     * - /users/{id} → UsersId
     * - /api/v1/orders → ApiV1Orders
     * - /users/{userId}/posts → UsersUserIdPosts
     */
    private function pathToPascalCase(string $path): string
    {
        $path = trim($path, '/');

        $path = preg_replace('/\{([^}]+)\}/', '$1', $path) ?? $path;

        $segments = preg_split('/[\/\-_]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
        if ($segments === false || $segments === []) {
            return 'Root';
        }

        $result = '';
        foreach ($segments as $segment) {
            $sanitized = $this->sanitizeIdentifier($segment);
            if ($sanitized !== '') {
                $result .= ucfirst(strtolower($sanitized));
            }
        }

        return $result !== '' ? $result : 'Root';
    }

    /**
     * Get the appropriate suffix based on schema type.
     */
    private function getSuffix(SchemaType $schemaType): string
    {
        return match ($schemaType) {
            SchemaType::Request => 'Request',
            SchemaType::Response => 'Response',
        };
    }

    /**
     * Remove invalid PHP identifier characters.
     */
    private function sanitizeIdentifier(string $input): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $input);

        return $sanitized ?? '';
    }

    /**
     * Ensure the property name is a valid PHP identifier.
     */
    private function ensureValidPropertyName(string $name): string
    {
        if ($name === '') {
            return 'property';
        }

        if (preg_match('/^[0-9]/', $name)) {
            $name = 'prop' . ucfirst($name);
        }

        if ($this->isReservedWord($name)) {
            $name = $name . 'Value';
        }

        return $name;
    }

    /**
     * Check if a name is a PHP reserved word.
     */
    private function isReservedWord(string $name): bool
    {
        $reserved = [
            'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
            'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do',
            'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach',
            'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final',
            'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
            'implements', 'include', 'include_once', 'instanceof', 'insteadof',
            'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print',
            'private', 'protected', 'public', 'readonly', 'require', 'require_once',
            'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use',
            'var', 'while', 'xor', 'yield', 'yield from',
            'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void',
            'iterable', 'object', 'mixed', 'never', 'self', 'parent',
        ];

        return in_array(strtolower($name), $reserved, true);
    }
}
