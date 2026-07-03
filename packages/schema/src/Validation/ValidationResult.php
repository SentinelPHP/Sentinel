<?php

declare(strict_types=1);

namespace SentinelPHP\Schema\Validation;

/**
 * Result of a JSON Schema validation.
 */
final readonly class ValidationResult
{
    /**
     * @param bool $valid Whether the payload is valid
     * @param list<ValidationError> $errors Validation errors (empty if valid)
     * @param list<ValidationError> $warnings Non-fatal validation warnings
     */
    public function __construct(
        public bool $valid,
        public array $errors = [],
        public array $warnings = [],
    ) {
    }

    public static function valid(): self
    {
        return new self(valid: true);
    }

    /**
     * @param list<ValidationError> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(valid: false, errors: $errors);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return list<ValidationError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<ValidationError>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array{valid: bool, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => array_map(fn (ValidationError $e) => $e->toArray(), $this->errors),
            'warnings' => array_map(fn (ValidationError $w) => $w->toArray(), $this->warnings),
        ];
    }
}
