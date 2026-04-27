<?php

declare(strict_types=1);

namespace SentinelPHP\Dto;

/**
 * Value object representing a property definition for DTO generation.
 */
final readonly class PropertyDefinition
{
    public const NO_DEFAULT = '__NO_DEFAULT__';

    /**
     * @param string $name The PHP property name
     * @param MappedType $mappedType The mapped PHP type from TypeMapper
     * @param bool $isRequired Whether the property is required (from schema's required array)
     * @param mixed $defaultValue Default value from schema (use NO_DEFAULT constant for no default)
     * @param string|null $description Description from schema for docblock
     * @param string|null $format Format hint from schema (e.g., 'date-time', 'uuid')
     * @param list<string> $attributes PHP attributes to add to the property
     * @param string|null $jsonKey Original JSON key name (if different from PHP property name)
     */
    public function __construct(
        public string $name,
        public MappedType $mappedType,
        public bool $isRequired = true,
        public mixed $defaultValue = self::NO_DEFAULT,
        public ?string $description = null,
        public ?string $format = null,
        public array $attributes = [],
        public ?string $jsonKey = null,
    ) {
    }

    /**
     * Get the JSON key for serialization (falls back to property name).
     */
    public function getJsonKey(): string
    {
        return $this->jsonKey ?? $this->name;
    }

    /**
     * Check if the JSON key differs from the PHP property name.
     */
    public function hasCustomJsonKey(): bool
    {
        return $this->jsonKey !== null && $this->jsonKey !== $this->name;
    }

    /**
     * Check if this property has a default value.
     */
    public function hasDefaultValue(): bool
    {
        return $this->defaultValue !== self::NO_DEFAULT;
    }

    /**
     * Check if this property is nullable.
     */
    public function isNullable(): bool
    {
        return !$this->isRequired || str_starts_with($this->mappedType->nativeType, '?');
    }

    /**
     * Get the effective default value for code generation.
     * Returns null for nullable properties without explicit default.
     */
    public function getEffectiveDefault(): mixed
    {
        if ($this->hasDefaultValue()) {
            return $this->defaultValue;
        }

        if ($this->isNullable()) {
            return null;
        }

        return self::NO_DEFAULT;
    }

    /**
     * Check if this property requires a docblock annotation.
     */
    public function requiresDocblock(): bool
    {
        return $this->mappedType->requiresDocblock() || $this->description !== null;
    }
}
