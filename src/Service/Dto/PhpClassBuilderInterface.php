<?php

declare(strict_types=1);

namespace App\Service\Dto;

use SentinelPHP\Dto\PropertyDefinition;

/**
 * Interface for building PHP class code from schema definitions.
 */
interface PhpClassBuilderInterface
{
    /**
     * Reset the builder to initial state.
     */
    public function reset(): self;

    /**
     * Set the namespace for the class.
     */
    public function setNamespace(string $namespace): self;

    /**
     * Set the class name.
     */
    public function setClassName(string $className): self;

    /**
     * Add a use statement (import).
     */
    public function addUseStatement(string $fqcn): self;

    /**
     * Set the class-level docblock content.
     */
    public function setClassDocblock(string $docblock): self;

    /**
     * Add a class-level attribute.
     */
    public function addClassAttribute(string $attribute): self;

    /**
     * Add a property to the class.
     */
    public function addProperty(PropertyDefinition $property): self;

    /**
     * Set whether the class should be readonly.
     */
    public function setReadonly(bool $readonly): self;

    /**
     * Set whether the class should be final.
     */
    public function setFinal(bool $final): self;

    /**
     * Set whether to generate getter methods.
     */
    public function setGenerateGetters(bool $generateGetters): self;

    /**
     * Set whether to generate serialization methods (fromArray/toArray).
     */
    public function setGenerateSerialization(bool $generateSerialization): self;

    /**
     * Set whether to implement JsonSerializable interface.
     */
    public function setGenerateJsonSerializable(bool $generateJsonSerializable): self;

    /**
     * Set whether to generate Symfony Serializer attributes.
     */
    public function setGenerateSerializerAttributes(bool $generateSerializerAttributes): self;

    /**
     * Set whether to generate validation method and attributes.
     */
    public function setGenerateValidation(bool $generateValidation): self;

    /**
     * Set the base class to extend.
     */
    public function setBaseClass(?string $baseClass): self;

    /**
     * Add an interface to implement.
     */
    public function addInterface(string $interface): self;

    /**
     * Add a trait to use.
     */
    public function addTrait(string $trait): self;

    /**
     * Build and return the PHP code.
     */
    public function build(): string;
}
