<?php

declare(strict_types=1);

namespace SentinelPHP\Schema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError as OpisValidationError;
use Opis\JsonSchema\Validator as OpisValidator;
use SentinelPHP\Schema\Validation\ValidationError;
use SentinelPHP\Schema\Validation\ValidationResult;

final class Validator implements ValidatorInterface
{
    private OpisValidator $validator;
    private ErrorFormatter $errorFormatter;

    /** @var array<string, object> */
    private array $schemaCache = [];

    private const MAX_CACHE_SIZE = 100;

    public function __construct()
    {
        $this->validator = new OpisValidator();
        $this->validator->setMaxErrors(100);
        $this->errorFormatter = new ErrorFormatter();
    }

    public function validate(array $payload, array $schema): ValidationResult
    {
        return $this->doValidate($payload, $schema);
    }

    public function validatePartial(array $payload, array $schema): ValidationResult
    {
        $partialSchema = $this->removeRequiredConstraints($schema);

        return $this->doValidate($payload, $partialSchema);
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @param array<string, mixed> $schema
     */
    private function doValidate(array $payload, array $schema): ValidationResult
    {
        $schemaObject = $this->getSchemaObject($schema);
        $dataObject = $this->convertToObject($payload);

        $result = $this->validator->validate($dataObject, $schemaObject);

        if ($result->isValid()) {
            return ValidationResult::valid();
        }

        $error = $result->error();
        if ($error === null) {
            return ValidationResult::valid();
        }

        $errors = $this->extractErrors($error);

        return ValidationResult::invalid($errors);
    }

    /**
     * Get or create a cached schema object to avoid repeated JSON encode/decode.
     *
     * @param array<string, mixed> $schema
     */
    private function getSchemaObject(array $schema): object
    {
        $cacheKey = $this->computeSchemaCacheKey($schema);

        if (isset($this->schemaCache[$cacheKey])) {
            return $this->schemaCache[$cacheKey];
        }

        // Evict oldest entries if cache is full
        if (count($this->schemaCache) >= self::MAX_CACHE_SIZE) {
            $this->schemaCache = array_slice($this->schemaCache, (int) (self::MAX_CACHE_SIZE / 2), null, true);
        }

        /** @var object $schemaObject */
        $schemaObject = $this->convertToObject($schema);
        $this->schemaCache[$cacheKey] = $schemaObject;

        return $schemaObject;
    }

    /**
     * Compute a cache key for a schema array.
     *
     * @param array<string, mixed> $schema
     */
    private function computeSchemaCacheKey(array $schema): string
    {
        return hash('xxh128', serialize($schema));
    }

    /**
     * Convert an array to a stdClass object recursively without JSON round-trip.
     *
     * @param array<mixed>|list<mixed> $data
     * @return object|array<mixed>
     */
    private function convertToObject(array $data): object|array
    {
        // Check if this is an indexed array (list)
        if ($data === [] || array_is_list($data)) {
            $result = [];
            foreach ($data as $value) {
                if (is_array($value)) {
                    $result[] = $this->convertToObject($value);
                } else {
                    $result[] = $value;
                }
            }
            return $result;
        }

        // Associative array - convert to object
        $object = new \stdClass();
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $object->{$key} = $this->convertToObject($value);
            } else {
                $object->{$key} = $value;
            }
        }

        return $object;
    }

    /**
     * @return list<ValidationError>
     */
    private function extractErrors(OpisValidationError $error): array
    {
        /** @var array<string, list<string>> $formattedErrors */
        $formattedErrors = $this->errorFormatter->format($error, true);
        $validationErrors = [];

        foreach ($formattedErrors as $path => $messages) {
            $jsonPath = $this->convertToJsonPath($path);

            foreach ($messages as $message) {
                $validationErrors[] = new ValidationError(
                    path: $jsonPath,
                    message: $message,
                    keyword: $this->extractKeywordFromError($error, $path),
                    expected: $this->extractExpectedValue($error, $path),
                    actual: $this->extractActualValue($error, $path),
                );
            }
        }

        return $validationErrors;
    }

    private function convertToJsonPath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '$';
        }

        $path = ltrim($path, '/');
        $segments = explode('/', $path);
        $jsonPath = '$';

        foreach ($segments as $segment) {
            $segment = str_replace('~1', '/', $segment);
            $segment = str_replace('~0', '~', $segment);

            if (is_numeric($segment)) {
                $jsonPath .= '[' . $segment . ']';
            } else {
                $jsonPath .= '.' . $segment;
            }
        }

        return $jsonPath;
    }

    private function extractKeywordFromError(OpisValidationError $error, string $targetPath): string
    {
        $errorPathArray = $error->data()->fullPath();
        $errorPath = '/' . implode('/', $errorPathArray);
        $targetPath = '/' . ltrim($targetPath, '/');

        if ($errorPath === $targetPath || $targetPath === '/') {
            return $error->keyword();
        }

        /** @var OpisValidationError $subError */
        foreach ($error->subErrors() as $subError) {
            $keyword = $this->extractKeywordFromError($subError, $targetPath);
            if ($keyword !== 'unknown') {
                return $keyword;
            }
        }

        return $error->keyword();
    }

    private function extractExpectedValue(OpisValidationError $error, string $targetPath): mixed
    {
        $args = $error->args();

        return match ($error->keyword()) {
            'type' => $args['expected'] ?? null,
            'required' => $args['missing'] ?? null,
            'format' => $args['format'] ?? null,
            'enum' => $args['expected'] ?? null,
            'minimum', 'maximum' => $args['limit'] ?? null,
            'minLength', 'maxLength' => $args['limit'] ?? null,
            'pattern' => $args['pattern'] ?? null,
            'additionalProperties' => false,
            default => null,
        };
    }

    private function extractActualValue(OpisValidationError $error, string $targetPath): mixed
    {
        $args = $error->args();

        return match ($error->keyword()) {
            'type' => $args['used'] ?? null,
            'additionalProperties' => $args['properties'] ?? null,
            default => null,
        };
    }

    /**
     * Recursively remove 'required' constraints from a schema for partial validation.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function removeRequiredConstraints(array $schema): array
    {
        unset($schema['required']);

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $propertySchema) {
                if (is_array($propertySchema)) {
                    /** @var array<string, mixed> $propertySchema */
                    $schema['properties'][$key] = $this->removeRequiredConstraints($propertySchema);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            /** @var array<string, mixed> $items */
            $items = $schema['items'];
            if (isset($items['anyOf']) && is_array($items['anyOf'])) {
                foreach ($items['anyOf'] as $index => $itemSchema) {
                    if (is_array($itemSchema)) {
                        /** @var array<string, mixed> $itemSchema */
                        $items['anyOf'][$index] = $this->removeRequiredConstraints($itemSchema);
                    }
                }
                $schema['items'] = $items;
            } else {
                $schema['items'] = $this->removeRequiredConstraints($items);
            }
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $combinator) {
            if (isset($schema[$combinator]) && is_array($schema[$combinator])) {
                foreach ($schema[$combinator] as $index => $subSchema) {
                    if (is_array($subSchema)) {
                        /** @var array<string, mixed> $subSchema */
                        $schema[$combinator][$index] = $this->removeRequiredConstraints($subSchema);
                    }
                }
            }
        }

        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            /** @var array<string, mixed> $additionalProperties */
            $additionalProperties = $schema['additionalProperties'];
            $schema['additionalProperties'] = $this->removeRequiredConstraints($additionalProperties);
        }

        return $schema;
    }
}
