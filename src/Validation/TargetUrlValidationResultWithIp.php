<?php

declare(strict_types=1);

namespace App\Validation;

final readonly class TargetUrlValidationResultWithIp
{
    private function __construct(
        public bool $isValid,
        public ?string $resolvedIp = null,
        public ?string $error = null,
    ) {
    }

    public static function valid(?string $resolvedIp): self
    {
        return new self(true, $resolvedIp);
    }

    public static function invalid(string $error): self
    {
        return new self(false, null, $error);
    }
}
