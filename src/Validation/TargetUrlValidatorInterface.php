<?php

declare(strict_types=1);

namespace App\Validation;

interface TargetUrlValidatorInterface
{
    public function validate(string $url): TargetUrlValidationResult;

    /**
     * Validate URL and return the resolved IP address to prevent DNS rebinding attacks.
     * The resolved IP should be used for the actual HTTP request.
     */
    public function validateWithResolvedIp(string $url): TargetUrlValidationResultWithIp;
}
