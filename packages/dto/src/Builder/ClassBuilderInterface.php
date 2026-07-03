<?php

declare(strict_types=1);

namespace SentinelPHP\Dto\Builder;

use SentinelPHP\Dto\PropertyDefinition;

interface ClassBuilderInterface
{
    /**
     * Reset the builder to its initial state.
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
     * Add a use statement.
     */
    public function addUseStatement(string $fqcn): self;

    /**
     * Set the class docblock.
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
     * Set whether to generate fromArray/toArray methods.
     */
    public function setGenerateSerialization(bool $generateSerialization): self;

    /**
     * Set whether to implement JsonSerializable.
     */
    public function setGenerateJsonSerializable(bool $generateJsonSerializable): self;

    /**
     * Set whether to generate Symfony Serializer attributes.
     */
    public function setGenerateSerializerAttributes(bool $generateSerializerAttributes): self;

    /**
     * Set whether to generate Symfony Validator attributes.
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
     * Build and return the PHP class code.
     */
    public function build(): string;
}
