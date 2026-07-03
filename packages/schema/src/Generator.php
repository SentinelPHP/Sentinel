<?php

declare(strict_types=1);

namespace SentinelPHP\Schema;

use SentinelPHP\Schema\Config\GeneratorConfig;

final class Generator implements GeneratorInterface
{
    private const SCHEMA_DRAFT = 'https://json-schema.org/draft/2020-12/schema';

    private const PATTERN_DATE_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';
    private const PATTERN_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const PATTERN_EMAIL = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';
    private const PATTERN_URI = '/^https?:\/\/[^\s]+$/i';

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload, ?GeneratorConfig $config = null): array
    {
        $config ??= GeneratorConfig::strict();

        $schema = [
            '$schema' => self::SCHEMA_DRAFT,
        ];

        return array_merge($schema, $this->generateForValue($payload, $config));
    }

    /**
     * @return array<string, mixed>
     */
    private function generateForValue(mixed $value, GeneratorConfig $config): array
    {
        if ($value === null) {
            return ['type' => 'null'];
        }

        if (is_bool($value)) {
            return $this->wrapTypeIfNullable(['type' => 'boolean'], $config);
        }

        if (is_int($value)) {
            return $this->wrapTypeIfNullable(['type' => 'integer'], $config);
        }

        if (is_float($value)) {
            return $this->wrapTypeIfNullable(['type' => 'number'], $config);
        }

        if (is_string($value)) {
            return $this->wrapTypeIfNullable($this->generateForString($value), $config);
        }

        if (is_array($value)) {
            if ($this->isAssociativeArray($value)) {
                /** @var array<string, mixed> $value */
                return $this->generateForObject($value, $config);
            }

            /** @var list<mixed> $value */
            return $this->wrapTypeIfNullable($this->generateForArray($value, $config), $config);
        }

        return $this->wrapTypeIfNullable(['type' => 'string'], $config);
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    private function generateForObject(array $object, GeneratorConfig $config): array
    {
        if (empty($object)) {
            $schema = [
                'type' => 'object',
                'additionalProperties' => $config->additionalProperties,
            ];

            return $this->wrapTypeIfNullable($schema, $config);
        }

        $properties = [];
        $required = [];

        foreach ($object as $key => $value) {
            $properties[$key] = $this->generateForValue($value, $config);
            if ($config->strictMode) {
                $required[] = $key;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => $config->additionalProperties,
        ];

        if ($config->strictMode && !empty($required)) {
            $schema['required'] = $required;
        }

        return $this->wrapTypeIfNullable($schema, $config);
    }

    /**
     * @param list<mixed> $array
     * @return array<string, mixed>
     */
    private function generateForArray(array $array, GeneratorConfig $config): array
    {
        if (empty($array)) {
            return [
                'type' => 'array',
            ];
        }

        $itemSchemas = [];
        foreach ($array as $item) {
            $itemSchema = $this->generateForValue($item, $config);
            $itemSchemas[] = $itemSchema;
        }

        $uniqueSchemas = $this->deduplicateSchemas($itemSchemas);

        if (count($uniqueSchemas) === 1) {
            return [
                'type' => 'array',
                'items' => $uniqueSchemas[0],
            ];
        }

        return [
            'type' => 'array',
            'items' => [
                'anyOf' => $uniqueSchemas,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $schemas
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateSchemas(array $schemas): array
    {
        $unique = [];
        $seen = [];

        foreach ($schemas as $schema) {
            $hash = $this->hashSchema($schema);
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $unique[] = $schema;
            }
        }

        return $unique;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function hashSchema(array $schema): string
    {
        return md5(json_encode($schema, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<mixed> $array
     */
    private function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function generateForString(string $value): array
    {
        $schema = ['type' => 'string'];

        if (preg_match(self::PATTERN_DATE_TIME, $value) === 1) {
            $schema['format'] = 'date-time';
        } elseif (preg_match(self::PATTERN_UUID, $value) === 1) {
            $schema['format'] = 'uuid';
        } elseif (preg_match(self::PATTERN_EMAIL, $value) === 1) {
            $schema['format'] = 'email';
        } elseif (preg_match(self::PATTERN_URI, $value) === 1) {
            $schema['format'] = 'uri';
        }

        return $schema;
    }

    /**
     * Wraps a schema type to allow null if nullableFields is enabled.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function wrapTypeIfNullable(array $schema, GeneratorConfig $config): array
    {
        if (!$config->nullableFields) {
            return $schema;
        }

        if (!isset($schema['type'])) {
            return $schema;
        }

        $type = $schema['type'];

        if ($type === 'null') {
            return $schema;
        }

        if (is_array($type) && in_array('null', $type, true)) {
            return $schema;
        }

        $schema['type'] = [$type, 'null'];

        return $schema;
    }
}
