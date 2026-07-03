<?php

declare(strict_types=1);

namespace SentinelPHP\Dto;

/**
 * Service for mapping JSON Schema types to PHP types.
 *
 * Handles primitive types, formats (date-time, uuid, etc.), union types,
 * nullable types, enums, and array types with proper docblock annotations.
 */
final class TypeMapper implements TypeMapperInterface
{
    private const FORMAT_DATETIME_TYPES = ['date-time', 'date'];

    /** @var array<string, mixed>|null Root schema for $ref resolution */
    private ?array $rootSchema = null;

    private const PRIMITIVE_TYPE_MAP = [
        'string' => 'string',
        'integer' => 'int',
        'number' => 'float',
        'boolean' => 'bool',
        'null' => 'null',
    ];

    public function __construct(
        private readonly string $enumNamespace = 'App\\Dto\\Generated\\Enum',
    ) {
    }

    public function setRootSchema(array $rootSchema): void
    {
        $this->rootSchema = $rootSchema;
    }

    public function clearRootSchema(): void
    {
        $this->rootSchema = null;
    }

    public function mapType(
        array $definition,
        bool $isRequired = true,
        ?string $propertyName = null,
        ?string $parentClassName = null,
    ): MappedType {
        // Resolve $ref first
        $definition = $this->resolveRef($definition);

        // Handle enum first (has 'enum' keyword)
        if ($this->isEnum($definition)) {
            return $this->mapEnumType($definition, $propertyName, $parentClassName, $isRequired);
        }

        // Handle union types (oneOf/anyOf)
        if (isset($definition['oneOf']) || isset($definition['anyOf'])) {
            return $this->mapUnionType($definition, $isRequired);
        }

        // Get the type(s) from the definition
        $type = $definition['type'] ?? 'mixed';
        /** @var string|null $format */
        $format = $definition['format'] ?? null;

        // Handle type arrays (e.g., ["string", "null"])
        if (is_array($type)) {
            /** @var list<string> $type */
            return $this->mapTypeArray($type, $format, $isRequired);
        }

        // Handle single type
        /** @var string $type */
        return $this->mapSingleType($type, $format, $definition, $isRequired);
    }

    public function isEnum(array $definition): bool
    {
        return isset($definition['enum']) && is_array($definition['enum']) && $definition['enum'] !== [];
    }

    public function isNestedObject(array $definition): bool
    {
        $type = $definition['type'] ?? null;

        if ($type === 'object' && isset($definition['properties'])) {
            return true;
        }

        return false;
    }

    /**
     * Map a single JSON Schema type to PHP.
     *
     * @param array<string, mixed> $definition
     */
    private function mapSingleType(string $type, ?string $format, array $definition, bool $isRequired): MappedType
    {
        // Handle format-specific mappings
        if ($format !== null) {
            $formatMapped = $this->mapFormat($type, $format, $isRequired);
            if ($formatMapped !== null) {
                return $formatMapped;
            }
        }

        // Handle array type
        if ($type === 'array') {
            return $this->mapArrayType($definition, $isRequired);
        }

        // Handle object type (nested DTO placeholder)
        if ($type === 'object') {
            return $this->mapObjectType($definition, $isRequired);
        }

        // Handle primitive types
        $phpType = self::PRIMITIVE_TYPE_MAP[$type] ?? 'mixed';

        if (!$isRequired && $phpType !== 'mixed') {
            return new MappedType(
                nativeType: '?' . $phpType,
                docblockType: $phpType . '|null',
            );
        }

        return new MappedType(nativeType: $phpType);
    }

    /**
     * Map a type array (e.g., ["string", "null"]) to PHP.
     *
     * @param list<string> $types
     */
    private function mapTypeArray(array $types, ?string $format, bool $isRequired): MappedType
    {
        $hasNull = in_array('null', $types, true);
        $nonNullTypes = array_values(array_filter($types, fn($t) => $t !== 'null'));

        // Single non-null type with null = nullable type
        if (count($nonNullTypes) === 1) {
            $singleType = $nonNullTypes[0];
            $mapped = $this->mapSingleType($singleType, $format, [], true);

            if ($hasNull || !$isRequired) {
                return new MappedType(
                    nativeType: '?' . $mapped->nativeType,
                    docblockType: $mapped->getDocblockType() . '|null',
                    imports: $mapped->imports,
                );
            }

            return $mapped;
        }

        // Multiple non-null types = union type
        return $this->buildUnionType($nonNullTypes, $hasNull || !$isRequired);
    }

    /**
     * Map oneOf/anyOf to PHP union type.
     *
     * @param array<string, mixed> $definition
     */
    private function mapUnionType(array $definition, bool $isRequired): MappedType
    {
        /** @var list<array<string, mixed>> $schemas */
        $schemas = $definition['oneOf'] ?? $definition['anyOf'] ?? [];
        /** @var list<string> $types */
        $types = [];
        /** @var list<string> $imports */
        $imports = [];
        $hasNull = false;

        foreach ($schemas as $schema) {
            $type = $schema['type'] ?? null;

            if ($type === 'null') {
                $hasNull = true;
                continue;
            }

            if (is_string($type) && isset(self::PRIMITIVE_TYPE_MAP[$type])) {
                $types[] = self::PRIMITIVE_TYPE_MAP[$type];
            }
        }

        if ($types === []) {
            return new MappedType(nativeType: 'mixed');
        }

        return $this->buildUnionType($types, $hasNull || !$isRequired, $imports);
    }

    /**
     * Build a PHP union type from multiple types.
     *
     * @param list<string> $types
     * @param list<string> $imports
     */
    private function buildUnionType(array $types, bool $nullable, array $imports = []): MappedType
    {
        // Map JSON types to PHP types
        $phpTypes = [];
        foreach ($types as $type) {
            $phpTypes[] = self::PRIMITIVE_TYPE_MAP[$type] ?? $type;
        }

        $phpTypes = array_unique($phpTypes);

        // Limit union complexity
        if (count($phpTypes) > 4) {
            return new MappedType(nativeType: 'mixed');
        }

        $unionType = implode('|', $phpTypes);

        if ($nullable) {
            // For single type, use nullable syntax
            if (count($phpTypes) === 1) {
                return new MappedType(
                    nativeType: '?' . $phpTypes[0],
                    docblockType: $phpTypes[0] . '|null',
                    imports: $imports,
                );
            }

            return new MappedType(
                nativeType: $unionType . '|null',
                imports: $imports,
            );
        }

        return new MappedType(
            nativeType: $unionType,
            imports: $imports,
        );
    }

    /**
     * Map format-specific types.
     */
    private function mapFormat(string $type, string $format, bool $isRequired): ?MappedType
    {
        // DateTime formats
        if ($type === 'string' && in_array($format, self::FORMAT_DATETIME_TYPES, true)) {
            $nativeType = $isRequired ? '\DateTimeImmutable' : '?\DateTimeImmutable';

            return new MappedType(
                nativeType: $nativeType,
                docblockType: $isRequired ? '\DateTimeImmutable' : '\DateTimeImmutable|null',
                imports: ['\DateTimeImmutable'],
            );
        }

        // UUID format - still string but with docblock hint
        if ($type === 'string' && $format === 'uuid') {
            $nativeType = $isRequired ? 'string' : '?string';

            return new MappedType(
                nativeType: $nativeType,
                docblockType: $isRequired ? 'string UUID' : 'string|null UUID',
            );
        }

        // Email, URI, etc. - just string
        if ($type === 'string' && in_array($format, ['email', 'uri', 'hostname', 'ipv4', 'ipv6'], true)) {
            $nativeType = $isRequired ? 'string' : '?string';

            return new MappedType(nativeType: $nativeType);
        }

        return null;
    }

    /**
     * Map array type with items schema.
     *
     * @param array<string, mixed> $definition
     */
    private function mapArrayType(array $definition, bool $isRequired): MappedType
    {
        $items = $definition['items'] ?? null;
        $nativeType = $isRequired ? 'array' : '?array';

        // No items schema - generic array
        if (!is_array($items)) {
            return new MappedType(
                nativeType: $nativeType,
                docblockType: $isRequired ? 'array<mixed>' : 'array<mixed>|null',
            );
        }

        // Get item type
        $itemType = $items['type'] ?? 'mixed';
        $itemFormat = $items['format'] ?? null;

        // Handle nested object arrays - mark for DTO generation
        if ($itemType === 'object' && isset($items['properties'])) {
            /** @var array<string, mixed> $items */
            return new MappedType(
                nativeType: $nativeType,
                docblockType: $isRequired ? 'array<object>' : 'array<object>|null',
                nestedSchema: $items,
                isArrayOfNested: true,
            );
        }

        // Handle primitive item types
        /** @var string $itemType */
        $phpItemType = self::PRIMITIVE_TYPE_MAP[$itemType] ?? 'mixed';

        // Handle DateTime items
        if ($itemType === 'string' && $itemFormat !== null && in_array($itemFormat, self::FORMAT_DATETIME_TYPES, true)) {
            return new MappedType(
                nativeType: $nativeType,
                docblockType: $isRequired ? 'array<\DateTimeImmutable>' : 'array<\DateTimeImmutable>|null',
                imports: ['\DateTimeImmutable'],
            );
        }

        $docblockType = $isRequired ? "array<{$phpItemType}>" : "array<{$phpItemType}>|null";

        return new MappedType(
            nativeType: $nativeType,
            docblockType: $docblockType,
        );
    }

    /**
     * Map object type (nested DTO or associative array).
     *
     * @param array<string, mixed> $definition
     */
    private function mapObjectType(array $definition, bool $isRequired): MappedType
    {
        // Has properties = nested object, mark for DTO generation
        if (isset($definition['properties'])) {
            // Return a placeholder that signals nested object generation is needed
            // The actual class name will be set by DtoGenerator
            return new MappedType(
                nativeType: $isRequired ? 'object' : '?object',
                docblockType: $isRequired ? 'object' : 'object|null',
                nestedSchema: $definition,
            );
        }

        // No properties = generic object/associative array
        $nativeType = $isRequired ? 'array' : '?array';

        return new MappedType(
            nativeType: $nativeType,
            docblockType: $isRequired ? 'array<string, mixed>' : 'array<string, mixed>|null',
        );
    }

    /**
     * Map enum type to PHP backed enum.
     *
     * @param array<string, mixed> $definition
     */
    private function mapEnumType(
        array $definition,
        ?string $propertyName,
        ?string $parentClassName,
        bool $isRequired,
    ): MappedType {
        /** @var list<string|int> $enumValues */
        $enumValues = $definition['enum'];

        // Determine backing type from values
        $backingType = $this->determineEnumBackingType($enumValues);

        // Generate enum name
        $enumName = $this->generateEnumName($propertyName, $parentClassName);

        // Generate enum code
        $enumCode = $this->generateEnumCode($enumName, $backingType, $enumValues);

        $generatedEnum = new GeneratedEnum(
            enumName: $enumName,
            namespace: $this->enumNamespace,
            backingType: $backingType,
            cases: $enumValues,
            phpCode: $enumCode,
        );

        $fullyQualified = $generatedEnum->getFullyQualifiedName();
        $nativeType = $isRequired ? $fullyQualified : '?' . $fullyQualified;

        return new MappedType(
            nativeType: $nativeType,
            docblockType: $isRequired ? $fullyQualified : $fullyQualified . '|null',
            imports: [$fullyQualified],
            generatedEnum: $generatedEnum,
        );
    }

    /**
     * Determine the backing type for an enum based on its values.
     *
     * @param list<string|int> $values
     */
    private function determineEnumBackingType(array $values): string
    {
        foreach ($values as $value) {
            if (!is_int($value)) {
                return 'string';
            }
        }

        return 'int';
    }

    /**
     * Generate an enum class name from property and parent class names.
     */
    private function generateEnumName(?string $propertyName, ?string $parentClassName): string
    {
        $parts = [];

        if ($parentClassName !== null) {
            // Remove common suffixes for cleaner enum names
            $cleanParent = preg_replace('/(Response|Request)$/', '', $parentClassName);
            if ($cleanParent !== '' && $cleanParent !== null) {
                $parts[] = $cleanParent;
            }
        }

        if ($propertyName !== null) {
            $parts[] = ucfirst($propertyName);
        }

        if ($parts === []) {
            return 'GeneratedEnum';
        }

        return implode('', $parts) . 'Enum';
    }

    /**
     * Generate PHP code for a backed enum.
     *
     * @param list<string|int> $values
     */
    private function generateEnumCode(string $enumName, string $backingType, array $values): string
    {
        $code = "<?php\n\n";
        $code .= "declare(strict_types=1);\n\n";
        $code .= "namespace {$this->enumNamespace};\n\n";
        $code .= "/**\n";
        $code .= " * Auto-generated enum.\n";
        $code .= " *\n";
        $code .= " * @generated This file was automatically generated.\n";
        $code .= " */\n";
        $code .= "enum {$enumName}: {$backingType}\n";
        $code .= "{\n";

        foreach ($values as $value) {
            $caseName = $this->generateEnumCaseName($value);
            $caseValue = is_string($value) ? "'{$value}'" : (string) $value;
            $code .= "    case {$caseName} = {$caseValue};\n";
        }

        $code .= "}\n";

        return $code;
    }

    /**
     * Generate a valid PHP enum case name from a value.
     */
    private function generateEnumCaseName(string|int $value): string
    {
        if (is_int($value)) {
            return 'Value' . $value;
        }

        // Convert to PascalCase
        $name = preg_replace('/[^a-zA-Z0-9]+/', ' ', $value) ?? $value;
        $name = ucwords($name);
        $name = str_replace(' ', '', $name);

        // Ensure it starts with a letter
        if ($name === '' || preg_match('/^[0-9]/', $name)) {
            $name = 'Value' . $name;
        }

        return $name;
    }

    /**
     * Resolve a $ref in the schema definition.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function resolveRef(array $definition): array
    {
        if (!isset($definition['$ref']) || !is_string($definition['$ref'])) {
            return $definition;
        }

        if ($this->rootSchema === null) {
            return $definition;
        }

        $ref = $definition['$ref'];

        // Only handle internal references (#/...)
        if (!str_starts_with($ref, '#/')) {
            return $definition;
        }

        $path = substr($ref, 2); // Remove '#/'
        $segments = explode('/', $path);

        $resolved = $this->rootSchema;
        foreach ($segments as $segment) {
            // Handle JSON pointer escaping
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (!is_array($resolved) || !isset($resolved[$segment])) {
                // Reference not found, return original
                return $definition;
            }

            /** @var mixed $resolved */
            $resolved = $resolved[$segment];
        }

        if (!is_array($resolved)) {
            return $definition;
        }

        // Recursively resolve in case the target also has a $ref
        /** @var array<string, mixed> $resolved */
        return $this->resolveRef($resolved);
    }
}
