<?php

declare(strict_types=1);

namespace SentinelPHP\Dto;

/**
 * Value object representing a generated PHP enum from JSON Schema.
 */
final readonly class GeneratedEnum
{
    /**
     * @param string $enumName The enum class name (e.g., 'UserStatus')
     * @param string $namespace The namespace for the enum
     * @param string $backingType The backing type ('string' or 'int')
     * @param list<string|int> $cases The enum case values
     * @param string $phpCode The generated PHP code for the enum
     */
    public function __construct(
        public string $enumName,
        public string $namespace,
        public string $backingType,
        public array $cases,
        public string $phpCode,
    ) {
    }

    /**
     * Get the fully qualified enum name.
     */
    public function getFullyQualifiedName(): string
    {
        return $this->namespace . '\\' . $this->enumName;
    }

    /**
     * Get the expected file path relative to the output directory.
     */
    public function getRelativeFilePath(): string
    {
        $namespacePath = str_replace('\\', '/', $this->namespace);
        return $namespacePath . '/' . $this->enumName . '.php';
    }
}
