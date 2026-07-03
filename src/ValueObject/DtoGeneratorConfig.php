<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Configuration options for PHP DTO generation.
 */
final readonly class DtoGeneratorConfig
{
    /**
     * @param string $defaultNamespace Default namespace prefix for generated DTOs
     * @param string $outputDirectory Output directory path for generated files
     * @param string $phpVersion Target PHP version (8.2, 8.3)
     * @param bool $readonlyProperties Generate readonly properties
     * @param bool $generateGetters Generate getter methods
     * @param bool $generateSerialization Generate fromArray/toArray methods
     * @param bool $generateJsonSerializable Implement JsonSerializable interface
     * @param bool $generateSerializerAttributes Generate Symfony Serializer attributes
     * @param bool $generateValidation Generate validate() method and Validator attributes
     * @param string|null $baseClass Base class for generated DTOs to extend
     * @param list<string> $interfaces Interfaces for generated DTOs to implement
     * @param list<string> $traits Traits for generated DTOs to use
     * @param array<string, string> $propertyMappings Global property name mappings (JSON key => PHP name)
     * @param list<string> $excludedProperties Properties to exclude from generation
     */
    public function __construct(
        public string $defaultNamespace = 'App\\Dto\\Generated',
        public string $outputDirectory = 'src/Dto/Generated',
        public string $phpVersion = '8.2',
        public bool $readonlyProperties = true,
        public bool $generateGetters = false,
        public bool $generateSerialization = true,
        public bool $generateJsonSerializable = true,
        public bool $generateSerializerAttributes = false,
        public bool $generateValidation = false,
        public ?string $baseClass = null,
        public array $interfaces = [],
        public array $traits = [],
        public array $propertyMappings = [],
        public array $excludedProperties = [],
    ) {
    }

    /**
     * Create a default configuration.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * Check if the target PHP version supports a feature.
     */
    public function supportsPhpVersion(string $minVersion): bool
    {
        return version_compare($this->phpVersion, $minVersion, '>=');
    }

    /**
     * Create a new config with values merged from another config.
     * Non-default values from $override take precedence.
     */
    public function merge(self $override): self
    {
        return new self(
            defaultNamespace: $override->defaultNamespace !== 'App\\Dto\\Generated' ? $override->defaultNamespace : $this->defaultNamespace,
            outputDirectory: $override->outputDirectory !== 'src/Dto/Generated' ? $override->outputDirectory : $this->outputDirectory,
            phpVersion: $override->phpVersion !== '8.2' ? $override->phpVersion : $this->phpVersion,
            readonlyProperties: $override->readonlyProperties,
            generateGetters: $override->generateGetters,
            generateSerialization: $override->generateSerialization,
            generateJsonSerializable: $override->generateJsonSerializable,
            generateSerializerAttributes: $override->generateSerializerAttributes,
            generateValidation: $override->generateValidation,
            baseClass: $override->baseClass ?? $this->baseClass,
            interfaces: $override->interfaces !== [] ? $override->interfaces : $this->interfaces,
            traits: $override->traits !== [] ? $override->traits : $this->traits,
            propertyMappings: array_merge($this->propertyMappings, $override->propertyMappings),
            excludedProperties: array_values(array_unique([...$this->excludedProperties, ...$override->excludedProperties])),
        );
    }

    /**
     * Create a config with a custom namespace.
     */
    public function withNamespace(string $namespace): self
    {
        return new self(
            defaultNamespace: $namespace,
            outputDirectory: $this->outputDirectory,
            phpVersion: $this->phpVersion,
            readonlyProperties: $this->readonlyProperties,
            generateGetters: $this->generateGetters,
            generateSerialization: $this->generateSerialization,
            generateJsonSerializable: $this->generateJsonSerializable,
            generateSerializerAttributes: $this->generateSerializerAttributes,
            generateValidation: $this->generateValidation,
            baseClass: $this->baseClass,
            interfaces: $this->interfaces,
            traits: $this->traits,
            propertyMappings: $this->propertyMappings,
            excludedProperties: $this->excludedProperties,
        );
    }

    /**
     * Create a config with additional property mappings.
     *
     * @param array<string, string> $mappings
     */
    public function withPropertyMappings(array $mappings): self
    {
        return new self(
            defaultNamespace: $this->defaultNamespace,
            outputDirectory: $this->outputDirectory,
            phpVersion: $this->phpVersion,
            readonlyProperties: $this->readonlyProperties,
            generateGetters: $this->generateGetters,
            generateSerialization: $this->generateSerialization,
            generateJsonSerializable: $this->generateJsonSerializable,
            generateSerializerAttributes: $this->generateSerializerAttributes,
            generateValidation: $this->generateValidation,
            baseClass: $this->baseClass,
            interfaces: $this->interfaces,
            traits: $this->traits,
            propertyMappings: array_merge($this->propertyMappings, $mappings),
            excludedProperties: $this->excludedProperties,
        );
    }

    /**
     * Check if a property should be excluded.
     */
    public function isPropertyExcluded(string $jsonPath): bool
    {
        foreach ($this->excludedProperties as $pattern) {
            if ($pattern === $jsonPath) {
                return true;
            }
            // Support simple wildcard matching
            if (str_contains($pattern, '*')) {
                $regex = '/^' . str_replace(['*', '/'], ['.*', '\\/'], $pattern) . '$/';
                if (preg_match($regex, $jsonPath)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the mapped property name for a JSON key.
     */
    public function getMappedPropertyName(string $jsonKey): ?string
    {
        return $this->propertyMappings[$jsonKey] ?? null;
    }
}
