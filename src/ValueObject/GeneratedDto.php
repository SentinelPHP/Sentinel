<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Entity\ApiSchema;

/**
 * Value object representing a generated PHP DTO class.
 */
final readonly class GeneratedDto
{
    /**
     * @param list<GeneratedDto> $nestedDtos Nested DTOs generated for this schema
     */
    public function __construct(
        public string $className,
        public string $namespace,
        public string $phpCode,
        public ApiSchema $schema,
        public \DateTimeImmutable $generatedAt,
        public array $nestedDtos = [],
    ) {
    }

    /**
     * Get the fully qualified class name.
     */
    public function getFullyQualifiedClassName(): string
    {
        return $this->namespace . '\\' . $this->className;
    }

    /**
     * Get the expected file path relative to the output directory.
     */
    public function getRelativeFilePath(): string
    {
        $namespacePath = str_replace('\\', '/', $this->namespace);
        return $namespacePath . '/' . $this->className . '.php';
    }

    /**
     * Get all DTOs including nested ones (flattened).
     *
     * @return list<GeneratedDto>
     */
    public function getAllDtos(): array
    {
        $all = [$this];

        foreach ($this->nestedDtos as $nested) {
            $all = [...$all, ...$nested->getAllDtos()];
        }

        return $all;
    }

    /**
     * Check if this DTO has nested DTOs.
     */
    public function hasNestedDtos(): bool
    {
        return $this->nestedDtos !== [];
    }
}
