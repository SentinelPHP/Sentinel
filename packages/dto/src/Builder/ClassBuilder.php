<?php

declare(strict_types=1);

namespace SentinelPHP\Dto\Builder;

use SentinelPHP\Dto\PropertyDefinition;

/**
 * Builder for generating PSR-12 compliant PHP class code.
 *
 * Supports readonly classes, constructor property promotion, docblocks,
 * PHP 8 attributes, and proper formatting.
 */
final class ClassBuilder implements ClassBuilderInterface
{
    private string $namespace = '';
    private string $className = '';
    /** @var list<string> */
    private array $useStatements = [];
    private string $classDocblock = '';
    /** @var list<string> */
    private array $classAttributes = [];
    /** @var list<PropertyDefinition> */
    private array $properties = [];
    private bool $readonly = true;
    private bool $final = true;
    private bool $generateGetters = false;
    private bool $generateSerialization = false;
    private bool $generateJsonSerializable = false;
    private bool $generateSerializerAttributes = false;
    private bool $generateValidation = false;
    private ?string $baseClass = null;
    /** @var list<string> */
    private array $interfaces = [];
    /** @var list<string> */
    private array $traits = [];

    public function reset(): self
    {
        $this->namespace = '';
        $this->className = '';
        $this->useStatements = [];
        $this->classDocblock = '';
        $this->classAttributes = [];
        $this->properties = [];
        $this->readonly = true;
        $this->final = true;
        $this->generateGetters = false;
        $this->generateSerialization = false;
        $this->generateJsonSerializable = false;
        $this->generateSerializerAttributes = false;
        $this->generateValidation = false;
        $this->baseClass = null;
        $this->interfaces = [];
        $this->traits = [];

        return $this;
    }

    public function setNamespace(string $namespace): self
    {
        $this->namespace = $namespace;

        return $this;
    }

    public function setClassName(string $className): self
    {
        $this->className = $className;

        return $this;
    }

    public function addUseStatement(string $fqcn): self
    {
        $normalized = ltrim($fqcn, '\\');
        if (!in_array($normalized, $this->useStatements, true)) {
            $this->useStatements[] = $normalized;
        }

        return $this;
    }

    public function setClassDocblock(string $docblock): self
    {
        $this->classDocblock = $docblock;

        return $this;
    }

    public function addClassAttribute(string $attribute): self
    {
        $this->classAttributes[] = $attribute;

        return $this;
    }

    public function addProperty(PropertyDefinition $property): self
    {
        $this->properties[] = $property;

        // Add imports from the mapped type
        foreach ($property->mappedType->imports as $import) {
            $this->addUseStatement($import);
        }

        return $this;
    }

    public function setReadonly(bool $readonly): self
    {
        $this->readonly = $readonly;

        return $this;
    }

    public function setFinal(bool $final): self
    {
        $this->final = $final;

        return $this;
    }

    public function setGenerateGetters(bool $generateGetters): self
    {
        $this->generateGetters = $generateGetters;

        return $this;
    }

    public function setGenerateSerialization(bool $generateSerialization): self
    {
        $this->generateSerialization = $generateSerialization;

        return $this;
    }

    public function setGenerateJsonSerializable(bool $generateJsonSerializable): self
    {
        $this->generateJsonSerializable = $generateJsonSerializable;

        return $this;
    }

    public function setGenerateSerializerAttributes(bool $generateSerializerAttributes): self
    {
        $this->generateSerializerAttributes = $generateSerializerAttributes;

        return $this;
    }

    public function setGenerateValidation(bool $generateValidation): self
    {
        $this->generateValidation = $generateValidation;

        return $this;
    }

    public function setBaseClass(?string $baseClass): self
    {
        $this->baseClass = $baseClass;

        return $this;
    }

    public function addInterface(string $interface): self
    {
        $normalized = ltrim($interface, '\\');
        if (!in_array($normalized, $this->interfaces, true)) {
            $this->interfaces[] = $normalized;
        }

        return $this;
    }

    public function addTrait(string $trait): self
    {
        $normalized = ltrim($trait, '\\');
        if (!in_array($normalized, $this->traits, true)) {
            $this->traits[] = $normalized;
        }

        return $this;
    }

    public function build(): string
    {
        // Add JsonSerializable import if needed
        if ($this->generateJsonSerializable) {
            $this->addUseStatement('JsonSerializable');
        }

        // Add Symfony Serializer imports if needed
        if ($this->generateSerializerAttributes) {
            $this->addUseStatement('Symfony\\Component\\Serializer\\Attribute\\Groups');
            foreach ($this->properties as $property) {
                if ($property->hasCustomJsonKey()) {
                    $this->addUseStatement('Symfony\\Component\\Serializer\\Attribute\\SerializedName');
                    break;
                }
            }
        }

        // Add Symfony Validator imports if needed
        if ($this->generateValidation) {
            $this->addValidatorImports();
        }

        // Add base class import if needed
        if ($this->baseClass !== null) {
            $this->addUseStatement($this->baseClass);
        }

        // Add interface imports
        foreach ($this->interfaces as $interface) {
            $this->addUseStatement($interface);
        }

        // Add trait imports
        foreach ($this->traits as $trait) {
            $this->addUseStatement($trait);
        }

        $code = "<?php\n\n";
        $code .= "declare(strict_types=1);\n\n";

        if ($this->namespace !== '') {
            $code .= "namespace {$this->namespace};\n\n";
        }

        $code .= $this->buildUseStatements();
        $code .= $this->buildClassDocblock();
        $code .= $this->buildClassAttributes();
        $code .= $this->buildClassDeclaration();
        $code .= "{\n";
        $code .= $this->buildTraitUses();
        $code .= $this->buildConstructor();
        $code .= $this->buildGetters();
        $code .= $this->buildSerializationMethods();
        $code .= "}\n";

        return $code;
    }

    private function buildUseStatements(): string
    {
        if ($this->useStatements === []) {
            return '';
        }

        $statements = $this->useStatements;
        sort($statements);

        $code = '';
        foreach ($statements as $fqcn) {
            $code .= "use {$fqcn};\n";
        }

        return $code . "\n";
    }

    private function buildClassDocblock(): string
    {
        if ($this->classDocblock === '') {
            return '';
        }

        $lines = explode("\n", $this->classDocblock);
        $code = "/**\n";

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $code .= " *\n";
            } else {
                $code .= " * {$trimmed}\n";
            }
        }

        $code .= " */\n";

        return $code;
    }

    private function buildClassAttributes(): string
    {
        if ($this->classAttributes === []) {
            return '';
        }

        $code = '';
        foreach ($this->classAttributes as $attribute) {
            $code .= "#[{$attribute}]\n";
        }

        return $code;
    }

    private function buildClassDeclaration(): string
    {
        $parts = [];

        if ($this->final) {
            $parts[] = 'final';
        }

        if ($this->readonly) {
            $parts[] = 'readonly';
        }

        $parts[] = 'class';
        $parts[] = $this->className;

        if ($this->baseClass !== null) {
            $parts[] = 'extends';
            $parts[] = $this->getShortClassName($this->baseClass);
        }

        $implements = [];
        foreach ($this->interfaces as $interface) {
            $implements[] = $this->getShortClassName($interface);
        }
        if ($this->generateJsonSerializable && !in_array('JsonSerializable', $implements, true)) {
            $implements[] = 'JsonSerializable';
        }

        if ($implements !== []) {
            $parts[] = 'implements';
            $parts[] = implode(', ', $implements);
        }

        return implode(' ', $parts) . "\n";
    }

    private function buildTraitUses(): string
    {
        if ($this->traits === []) {
            return '';
        }

        $code = '';
        foreach ($this->traits as $trait) {
            $shortName = $this->getShortClassName($trait);
            $code .= "    use {$shortName};\n";
        }

        return $code . "\n";
    }

    private function getShortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    private function buildConstructor(): string
    {
        if ($this->properties === []) {
            return '';
        }

        $sortedProperties = $this->sortProperties();

        $code = "    public function __construct(\n";
        $propertyLines = [];

        foreach ($sortedProperties as $property) {
            $propertyLines[] = $this->buildPropertyLine($property);
        }

        $code .= implode(",\n", $propertyLines) . ",\n";
        $code .= "    ) {\n";
        $code .= "    }\n";

        return $code;
    }

    /**
     * @return list<PropertyDefinition>
     */
    private function sortProperties(): array
    {
        $required = [];
        $optional = [];

        foreach ($this->properties as $property) {
            $effectiveDefault = $property->getEffectiveDefault();
            if ($effectiveDefault === PropertyDefinition::NO_DEFAULT) {
                $required[] = $property;
            } else {
                $optional[] = $property;
            }
        }

        return [...$required, ...$optional];
    }

    private function buildPropertyLine(PropertyDefinition $property): string
    {
        $lines = [];

        $docblock = $this->buildPropertyDocblock($property);
        if ($docblock !== '') {
            $lines[] = $docblock;
        }

        foreach ($property->attributes as $attribute) {
            $lines[] = "        #[{$attribute}]";
        }

        if ($this->generateSerializerAttributes) {
            $lines[] = "        #[Groups(['read', 'write'])]";

            if ($property->hasCustomJsonKey()) {
                $jsonKey = $property->getJsonKey();
                $lines[] = "        #[SerializedName('{$jsonKey}')]";
            }
        }

        if ($this->generateValidation) {
            $validatorAttributes = $this->buildValidatorAttributes($property);
            foreach ($validatorAttributes as $attr) {
                $lines[] = "        #[{$attr}]";
            }
        }

        $declaration = $this->buildPropertyDeclaration($property);
        $lines[] = $declaration;

        return implode("\n", $lines);
    }

    private function buildPropertyDocblock(PropertyDefinition $property): string
    {
        if (!$property->requiresDocblock()) {
            return '';
        }

        $parts = [];

        if ($property->mappedType->requiresDocblock()) {
            $parts[] = '@var ' . $property->mappedType->getDocblockType();
        }

        if ($property->description !== null) {
            if ($parts !== []) {
                $parts[] = '';
            }
            $parts[] = $property->description;
        }

        if ($parts === []) {
            return '';
        }

        $code = "        /**\n";
        foreach ($parts as $part) {
            if ($part === '') {
                $code .= "         *\n";
            } else {
                $code .= "         * {$part}\n";
            }
        }
        $code .= "         */";

        return $code;
    }

    private function buildPropertyDeclaration(PropertyDefinition $property): string
    {
        $type = $property->mappedType->nativeType;
        $name = $property->name;
        $effectiveDefault = $property->getEffectiveDefault();

        $declaration = "        public {$type} \${$name}";

        if ($effectiveDefault !== PropertyDefinition::NO_DEFAULT) {
            $declaration .= ' = ' . $this->formatDefaultValue($effectiveDefault);
        }

        return $declaration;
    }

    private function formatDefaultValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            return $this->formatArrayValue($value);
        }

        return 'null';
    }

    /**
     * @param array<mixed> $value
     */
    private function formatArrayValue(array $value): string
    {
        $isAssociative = array_keys($value) !== range(0, count($value) - 1);

        $items = [];
        foreach ($value as $key => $item) {
            $formattedItem = $this->formatDefaultValue($item);
            if ($isAssociative) {
                $formattedKey = is_string($key) ? "'" . addslashes($key) . "'" : $key;
                $items[] = "{$formattedKey} => {$formattedItem}";
            } else {
                $items[] = $formattedItem;
            }
        }

        return '[' . implode(', ', $items) . ']';
    }

    private function buildGetters(): string
    {
        if (!$this->generateGetters || $this->properties === []) {
            return '';
        }

        $code = "\n";

        foreach ($this->properties as $property) {
            $methodName = 'get' . ucfirst($property->name);
            $returnType = $property->mappedType->nativeType;

            if ($property->mappedType->requiresDocblock()) {
                $code .= "    /**\n";
                $code .= "     * @return {$property->mappedType->getDocblockType()}\n";
                $code .= "     */\n";
            }

            $code .= "    public function {$methodName}(): {$returnType}\n";
            $code .= "    {\n";
            $code .= "        return \$this->{$property->name};\n";
            $code .= "    }\n\n";
        }

        return rtrim($code, "\n") . "\n";
    }

    private function buildSerializationMethods(): string
    {
        if (!$this->generateSerialization && !$this->generateJsonSerializable) {
            return '';
        }

        $code = '';

        if ($this->generateSerialization) {
            $code .= $this->buildFromArrayMethod();
            $code .= $this->buildToArrayMethod();
        }

        if ($this->generateJsonSerializable) {
            $code .= $this->buildJsonSerializeMethod();
        }

        return $code;
    }

    private function buildFromArrayMethod(): string
    {
        $code = "\n    /**\n";
        $code .= "     * Create an instance from an array.\n";
        $code .= "     *\n";
        $code .= "     * @param array<string, mixed> \$data\n";
        $code .= "     */\n";
        $code .= "    public static function fromArray(array \$data): self\n";
        $code .= "    {\n";
        $code .= "        return new self(\n";

        $sortedProperties = $this->sortProperties();
        $propertyAssignments = [];

        foreach ($sortedProperties as $property) {
            $propertyAssignments[] = $this->buildFromArrayPropertyAssignment($property);
        }

        $code .= implode(",\n", $propertyAssignments) . ",\n";
        $code .= "        );\n";
        $code .= "    }\n";

        return $code;
    }

    private function buildFromArrayPropertyAssignment(PropertyDefinition $property): string
    {
        $jsonKey = $property->getJsonKey();
        $name = $property->name;

        $extraction = $this->buildFromArrayExtraction($property, $jsonKey);

        return "            {$name}: {$extraction}";
    }

    private function buildFromArrayExtraction(PropertyDefinition $property, string $jsonKey): string
    {
        $type = $property->mappedType;
        $isNullable = $property->isNullable();
        $effectiveDefault = $property->getEffectiveDefault();

        $accessor = "\$data['{$jsonKey}']";

        if ($effectiveDefault !== PropertyDefinition::NO_DEFAULT) {
            $defaultFormatted = $this->formatDefaultValue($effectiveDefault);
            $accessor = "\$data['{$jsonKey}'] ?? {$defaultFormatted}";
        } elseif ($isNullable) {
            $accessor = "\$data['{$jsonKey}'] ?? null";
        }

        if ($type->nestedClassName !== null) {
            $nestedClass = $type->nestedClassName;
            if ($type->isArrayOfNested) {
                if ($isNullable) {
                    return "isset(\$data['{$jsonKey}']) ? array_map(static fn(array \$item): {$nestedClass} => {$nestedClass}::fromArray(\$item), \$data['{$jsonKey}']) : null";
                }
                return "array_map(static fn(array \$item): {$nestedClass} => {$nestedClass}::fromArray(\$item), {$accessor})";
            }
            if ($isNullable) {
                return "isset(\$data['{$jsonKey}']) ? {$nestedClass}::fromArray(\$data['{$jsonKey}']) : null";
            }
            return "{$nestedClass}::fromArray(\$data['{$jsonKey}'])";
        }

        if ($type->generatedEnum !== null) {
            $enumClass = $type->generatedEnum->enumName;
            if ($isNullable) {
                return "isset(\$data['{$jsonKey}']) ? {$enumClass}::from(\$data['{$jsonKey}']) : null";
            }
            return "{$enumClass}::from(\$data['{$jsonKey}'])";
        }

        if (str_contains($type->nativeType, 'DateTimeImmutable')) {
            if ($isNullable) {
                return "isset(\$data['{$jsonKey}']) ? new \\DateTimeImmutable(\$data['{$jsonKey}']) : null";
            }
            return "new \\DateTimeImmutable(\$data['{$jsonKey}'])";
        }

        $nativeType = ltrim($type->nativeType, '?');
        if (in_array($nativeType, ['int', 'float', 'string', 'bool'], true)) {
            if ($isNullable) {
                return $this->buildNullablePrimitiveCoercion($jsonKey, $nativeType);
            }
            return $this->buildPrimitiveCoercion($accessor, $nativeType);
        }

        return $accessor;
    }

    private function buildNullablePrimitiveCoercion(string $jsonKey, string $type): string
    {
        return match ($type) {
            'int' => "isset(\$data['{$jsonKey}']) ? (int) \$data['{$jsonKey}'] : null",
            'float' => "isset(\$data['{$jsonKey}']) ? (float) \$data['{$jsonKey}'] : null",
            'string' => "isset(\$data['{$jsonKey}']) ? (string) \$data['{$jsonKey}'] : null",
            'bool' => "isset(\$data['{$jsonKey}']) ? (bool) \$data['{$jsonKey}'] : null",
            default => "\$data['{$jsonKey}'] ?? null",
        };
    }

    private function buildPrimitiveCoercion(string $accessor, string $type): string
    {
        return match ($type) {
            'int' => "(int) ({$accessor})",
            'float' => "(float) ({$accessor})",
            'string' => "(string) ({$accessor})",
            'bool' => "(bool) ({$accessor})",
            default => $accessor,
        };
    }

    private function buildToArrayMethod(): string
    {
        $code = "\n    /**\n";
        $code .= "     * Convert to an associative array.\n";
        $code .= "     *\n";
        $code .= "     * @return array<string, mixed>\n";
        $code .= "     */\n";
        $code .= "    public function toArray(): array\n";
        $code .= "    {\n";
        $code .= "        return [\n";

        foreach ($this->properties as $property) {
            $code .= $this->buildToArrayPropertyLine($property);
        }

        $code .= "        ];\n";
        $code .= "    }\n";

        return $code;
    }

    private function buildToArrayPropertyLine(PropertyDefinition $property): string
    {
        $jsonKey = $property->getJsonKey();

        $value = $this->buildToArrayValue($property);

        return "            '{$jsonKey}' => {$value},\n";
    }

    private function buildToArrayValue(PropertyDefinition $property): string
    {
        $name = $property->name;
        $type = $property->mappedType;
        $isNullable = $property->isNullable();

        if ($type->nestedClassName !== null) {
            if ($type->isArrayOfNested) {
                if ($isNullable) {
                    return "\$this->{$name} !== null ? array_map(static fn(object \$item): array => \$item->toArray(), \$this->{$name}) : null";
                }
                return "array_map(static fn(object \$item): array => \$item->toArray(), \$this->{$name})";
            }
            if ($isNullable) {
                return "\$this->{$name}?->toArray()";
            }
            return "\$this->{$name}->toArray()";
        }

        if ($type->generatedEnum !== null) {
            if ($isNullable) {
                return "\$this->{$name}?->value";
            }
            return "\$this->{$name}->value";
        }

        if (str_contains($type->nativeType, 'DateTimeImmutable')) {
            if ($isNullable) {
                return "\$this->{$name}?->format(\\DateTimeInterface::ATOM)";
            }
            return "\$this->{$name}->format(\\DateTimeInterface::ATOM)";
        }

        return "\$this->{$name}";
    }

    private function buildJsonSerializeMethod(): string
    {
        $code = "\n    /**\n";
        $code .= "     * Serialize to JSON.\n";
        $code .= "     *\n";
        $code .= "     * @return array<string, mixed>\n";
        $code .= "     */\n";
        $code .= "    public function jsonSerialize(): array\n";
        $code .= "    {\n";

        if ($this->generateSerialization) {
            $code .= "        return \$this->toArray();\n";
        } else {
            $code .= "        return [\n";
            foreach ($this->properties as $property) {
                $code .= $this->buildToArrayPropertyLine($property);
            }
            $code .= "        ];\n";
        }

        $code .= "    }\n";

        return $code;
    }

    private function addValidatorImports(): void
    {
        foreach ($this->properties as $property) {
            if ($property->isRequired) {
                $nativeType = ltrim($property->mappedType->nativeType, '?');
                if ($nativeType === 'string') {
                    $this->addUseStatement('Symfony\\Component\\Validator\\Constraints\\NotBlank');
                } else {
                    $this->addUseStatement('Symfony\\Component\\Validator\\Constraints\\NotNull');
                }
            }

            if ($property->format !== null) {
                match ($property->format) {
                    'email' => $this->addUseStatement('Symfony\\Component\\Validator\\Constraints\\Email'),
                    'uuid' => $this->addUseStatement('Symfony\\Component\\Validator\\Constraints\\Uuid'),
                    'uri', 'url' => $this->addUseStatement('Symfony\\Component\\Validator\\Constraints\\Url'),
                    default => null,
                };
            }
        }
    }

    /**
     * @return list<string>
     */
    private function buildValidatorAttributes(PropertyDefinition $property): array
    {
        $attributes = [];

        if ($property->isRequired) {
            $nativeType = ltrim($property->mappedType->nativeType, '?');
            if ($nativeType === 'string') {
                $attributes[] = 'NotBlank';
            } else {
                $attributes[] = 'NotNull';
            }
        }

        if ($property->format !== null) {
            $constraint = match ($property->format) {
                'email' => 'Email',
                'uuid' => 'Uuid',
                'uri', 'url' => 'Url',
                default => null,
            };

            if ($constraint !== null) {
                $attributes[] = $constraint;
            }
        }

        return $attributes;
    }
}
