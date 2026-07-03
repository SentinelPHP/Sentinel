<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Context object for tracking state during DTO generation.
 *
 * Tracks already generated classes, detects circular references,
 * and manages pending nested objects to generate.
 */
final class DtoGenerationContext
{
    /** @var array<string, GeneratedDto> Already generated classes keyed by schema hash */
    private array $generatedClasses = [];

    /** @var array<string, true> Schema hashes currently being processed (cycle detection) */
    private array $processingStack = [];

    /** @var list<array{schemaHash: string, schema: array<string, mixed>, parentClassName: string, propertyName: string}> */
    private array $pendingNestedObjects = [];

    /**
     * Check if a schema has already been generated.
     */
    public function isAlreadyGenerated(string $schemaHash): bool
    {
        return isset($this->generatedClasses[$schemaHash]);
    }

    /**
     * Get a previously generated DTO by schema hash.
     */
    public function getGenerated(string $schemaHash): ?GeneratedDto
    {
        return $this->generatedClasses[$schemaHash] ?? null;
    }

    /**
     * Mark a schema as generated.
     */
    public function markGenerated(string $schemaHash, GeneratedDto $dto): void
    {
        $this->generatedClasses[$schemaHash] = $dto;
    }

    /**
     * Check if a schema is currently being processed (circular reference).
     */
    public function isProcessing(string $schemaHash): bool
    {
        return isset($this->processingStack[$schemaHash]);
    }

    /**
     * Push a schema onto the processing stack.
     */
    public function pushProcessing(string $schemaHash): void
    {
        $this->processingStack[$schemaHash] = true;
    }

    /**
     * Pop a schema from the processing stack.
     */
    public function popProcessing(string $schemaHash): void
    {
        unset($this->processingStack[$schemaHash]);
    }

    /**
     * Add a nested object to the pending queue.
     *
     * @param array<string, mixed> $schema
     */
    public function addPending(
        string $schemaHash,
        array $schema,
        string $parentClassName,
        string $propertyName,
    ): void {
        $this->pendingNestedObjects[] = [
            'schemaHash' => $schemaHash,
            'schema' => $schema,
            'parentClassName' => $parentClassName,
            'propertyName' => $propertyName,
        ];
    }

    /**
     * Check if there are pending nested objects to generate.
     */
    public function hasPending(): bool
    {
        return $this->pendingNestedObjects !== [];
    }

    /**
     * Pop the next pending nested object from the queue.
     *
     * @return array{schemaHash: string, schema: array<string, mixed>, parentClassName: string, propertyName: string}|null
     */
    public function popPending(): ?array
    {
        if ($this->pendingNestedObjects === []) {
            return null;
        }

        return array_shift($this->pendingNestedObjects);
    }

    /**
     * Get all generated DTOs.
     *
     * @return array<string, GeneratedDto>
     */
    public function getAllGenerated(): array
    {
        return $this->generatedClasses;
    }

    /**
     * Compute a hash for a JSON schema to identify duplicates.
     *
     * @param array<string, mixed> $schema
     */
    public static function computeSchemaHash(array $schema): string
    {
        // Normalize the schema by sorting keys recursively
        $normalized = self::normalizeSchema($schema);

        return hash('xxh128', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Normalize a schema for consistent hashing.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function normalizeSchema(array $schema): array
    {
        ksort($schema);

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $schema[$key] = self::normalizeSchema($value);
            }
        }

        return $schema;
    }
}
