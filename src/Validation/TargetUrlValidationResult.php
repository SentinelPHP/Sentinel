<?php

declare(strict_types=1);

namespace App\Validation;

final readonly class TargetUrlValidationResult
{
    private function __construct(
        public bool $isValid,
        public ?string $error = null,
    ) {
    }

    public static function valid(): self
    {
        return new self(true);
    }

    public static function invalid(string $error): self
    {
        return new self(false, $error);
    }
}
