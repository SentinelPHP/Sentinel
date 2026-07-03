<?php

declare(strict_types=1);

namespace SentinelPHP\Dto;

/**
 * Value object representing a mapped PHP type from JSON Schema.
 *
 * Contains the native PHP type declaration, optional docblock type for
 * generics/complex types, and any required import statements.
 */
final readonly class MappedType
{
    /**
     * @param string $nativeType The PHP native type (e.g., 'string', 'int', '?string', 'array')
     * @param string|null $docblockType The docblock type for generics (e.g., 'array<string>', 'string UUID')
     * @param list<string> $imports Required use statements (e.g., ['\DateTimeImmutable'])
     * @param GeneratedEnum|null $generatedEnum Generated enum class if type is an enum
     * @param string|null $nestedClassName Nested DTO class name if type is an object (set after generation)
     * @param array<string, mixed>|null $nestedSchema The nested object schema for DTO generation
     * @param bool $isArrayOfNested Whether this is an array of nested objects
     */
    public function __construct(
        public string $nativeType,
        public ?string $docblockType = null,
        public array $imports = [],
        public ?GeneratedEnum $generatedEnum = null,
        public ?string $nestedClassName = null,
        public ?array $nestedSchema = null,
        public bool $isArrayOfNested = false,
    ) {
    }

    /**
     * Check if this type requires a docblock annotation.
     */
    public function requiresDocblock(): bool
    {
        return $this->docblockType !== null && $this->docblockType !== $this->nativeType;
    }

    /**
     * Check if this type has imports.
     */
    public function hasImports(): bool
    {
        return $this->imports !== [];
    }

    /**
     * Check if this type generated an enum.
     */
    public function hasGeneratedEnum(): bool
    {
        return $this->generatedEnum !== null;
    }

    /**
     * Check if this type represents a nested object.
     */
    public function isNestedObject(): bool
    {
        return $this->nestedClassName !== null || $this->nestedSchema !== null;
    }

    /**
     * Check if this type has a nested schema that needs DTO generation.
     */
    public function hasNestedSchema(): bool
    {
        return $this->nestedSchema !== null;
    }

    /**
     * Create a new MappedType with the nested class name set.
     */
    public function withNestedClassName(string $className, string $namespace): self
    {
        $fullyQualified = $namespace . '\\' . $className;
        $nativeType = str_contains($this->nativeType, '?') ? '?' . $fullyQualified : $fullyQualified;

        if ($this->isArrayOfNested) {
            $nativeType = str_contains($this->nativeType, '?') ? '?array' : 'array';
            $docblockType = str_contains($this->nativeType, '?')
                ? "array<{$fullyQualified}>|null"
                : "array<{$fullyQualified}>";
        } else {
            $docblockType = str_contains($this->nativeType, '?')
                ? $fullyQualified . '|null'
                : $fullyQualified;
        }

        return new self(
            nativeType: $nativeType,
            docblockType: $docblockType,
            imports: [...$this->imports, $fullyQualified],
            generatedEnum: $this->generatedEnum,
            nestedClassName: $className,
            nestedSchema: null, // Clear schema after resolution
            isArrayOfNested: $this->isArrayOfNested,
        );
    }

    /**
     * Get the type string for docblock (falls back to native type).
     */
    public function getDocblockType(): string
    {
        return $this->docblockType ?? $this->nativeType;
    }
}
