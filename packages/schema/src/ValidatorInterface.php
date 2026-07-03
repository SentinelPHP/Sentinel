<?php

declare(strict_types=1);

namespace SentinelPHP\Schema;

use SentinelPHP\Schema\Validation\ValidationResult;

interface ValidatorInterface
{
    /**
     * Validate a payload against a JSON Schema.
     *
     * @param array<string, mixed>|list<mixed> $payload The data to validate
     * @param array<string, mixed> $schema The JSON Schema to validate against
     * @return ValidationResult The validation result with errors if invalid
     */
    public function validate(array $payload, array $schema): ValidationResult;

    /**
     * Validate only the fields present in the payload (partial validation).
     * Missing required fields will not cause validation errors.
     *
     * @param array<string, mixed>|list<mixed> $payload The data to validate
     * @param array<string, mixed> $schema The JSON Schema to validate against
     * @return ValidationResult The validation result with errors if invalid
     */
    public function validatePartial(array $payload, array $schema): ValidationResult;
}
