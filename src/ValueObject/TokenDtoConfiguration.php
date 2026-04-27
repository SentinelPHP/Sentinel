<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Per-token DTO generation configuration.
 *
 * This allows overriding global DTO settings on a per-token basis.
 */
final readonly class TokenDtoConfiguration
{
    /**
     * @param string|null $customNamespace Custom namespace for this token's DTOs
     * @param string|null $namingStrategyClass Custom naming strategy class (FQCN)
     * @param array<string, string> $propertyMappings Property name mappings (JSON key => PHP name)
     * @param list<string> $excludedProperties Properties to exclude from generation
     * @param string|null $baseClass Base class for DTOs to extend
     * @param list<string> $interfaces Interfaces for DTOs to implement
     * @param list<string> $traits Traits for DTOs to use
     * @param bool|null $generateSerialization Override for serialization generation
     * @param bool|null $generateGetters Override for getter generation
     */
    public function __construct(
        public ?string $customNamespace = null,
        public ?string $namingStrategyClass = null,
        public array $propertyMappings = [],
        public array $excludedProperties = [],
        public ?string $baseClass = null,
        public array $interfaces = [],
        public array $traits = [],
        public ?bool $generateSerialization = null,
        public ?bool $generateGetters = null,
    ) {
    }

    /**
     * Create from an array (for JSON deserialization).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var string|null $customNamespace */
        $customNamespace = $data['customNamespace'] ?? null;
        /** @var string|null $namingStrategyClass */
        $namingStrategyClass = $data['namingStrategyClass'] ?? null;
        /** @var array<string, string> $propertyMappings */
        $propertyMappings = $data['propertyMappings'] ?? [];
        /** @var list<string> $excludedProperties */
        $excludedProperties = $data['excludedProperties'] ?? [];
        /** @var string|null $baseClass */
        $baseClass = $data['baseClass'] ?? null;
        /** @var list<string> $interfaces */
        $interfaces = $data['interfaces'] ?? [];
        /** @var list<string> $traits */
        $traits = $data['traits'] ?? [];
        /** @var bool|null $generateSerialization */
        $generateSerialization = $data['generateSerialization'] ?? null;
        /** @var bool|null $generateGetters */
        $generateGetters = $data['generateGetters'] ?? null;

        return new self(
            customNamespace: $customNamespace,
            namingStrategyClass: $namingStrategyClass,
            propertyMappings: $propertyMappings,
            excludedProperties: $excludedProperties,
            baseClass: $baseClass,
            interfaces: $interfaces,
            traits: $traits,
            generateSerialization: $generateSerialization,
            generateGetters: $generateGetters,
        );
    }

    /**
     * Convert to array (for JSON serialization).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'customNamespace' => $this->customNamespace,
            'namingStrategyClass' => $this->namingStrategyClass,
            'propertyMappings' => $this->propertyMappings !== [] ? $this->propertyMappings : null,
            'excludedProperties' => $this->excludedProperties !== [] ? $this->excludedProperties : null,
            'baseClass' => $this->baseClass,
            'interfaces' => $this->interfaces !== [] ? $this->interfaces : null,
            'traits' => $this->traits !== [] ? $this->traits : null,
            'generateSerialization' => $this->generateSerialization,
            'generateGetters' => $this->generateGetters,
        ], static fn($value) => $value !== null);
    }

    /**
     * Check if this configuration has any overrides.
     */
    public function hasOverrides(): bool
    {
        return $this->customNamespace !== null
            || $this->namingStrategyClass !== null
            || $this->propertyMappings !== []
            || $this->excludedProperties !== []
            || $this->baseClass !== null
            || $this->interfaces !== []
            || $this->traits !== []
            || $this->generateSerialization !== null
            || $this->generateGetters !== null;
    }

    /**
     * Merge this token configuration into a DtoGeneratorConfig.
     */
    public function mergeIntoConfig(DtoGeneratorConfig $globalConfig): DtoGeneratorConfig
    {
        return new DtoGeneratorConfig(
            defaultNamespace: $this->customNamespace ?? $globalConfig->defaultNamespace,
            outputDirectory: $globalConfig->outputDirectory,
            phpVersion: $globalConfig->phpVersion,
            readonlyProperties: $globalConfig->readonlyProperties,
            generateGetters: $this->generateGetters ?? $globalConfig->generateGetters,
            generateSerialization: $this->generateSerialization ?? $globalConfig->generateSerialization,
            generateJsonSerializable: $globalConfig->generateJsonSerializable,
            generateSerializerAttributes: $globalConfig->generateSerializerAttributes,
            generateValidation: $globalConfig->generateValidation,
            baseClass: $this->baseClass ?? $globalConfig->baseClass,
            interfaces: $this->interfaces !== [] ? $this->interfaces : $globalConfig->interfaces,
            traits: $this->traits !== [] ? $this->traits : $globalConfig->traits,
            propertyMappings: array_merge($globalConfig->propertyMappings, $this->propertyMappings),
            excludedProperties: array_values(array_unique([...$globalConfig->excludedProperties, ...$this->excludedProperties])),
        );
    }

    /**
     * Create an empty configuration with no overrides.
     */
    public static function empty(): self
    {
        return new self();
    }
}
