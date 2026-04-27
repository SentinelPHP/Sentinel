<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;

interface SchemaValidationServiceInterface
{
    /**
     * Validates a response body against the master schema for the given endpoint.
     * Optionally validates request body if enabled on the token.
     * Records drift if validation fails.
     *
     * @param ApiToken $token The API token (must be in validating mode)
     * @param string $targetHost The target API host
     * @param string $path The endpoint path
     * @param string $method The HTTP method
     * @param string $responseBody The response body to validate
     * @param string|null $requestBody The request body to validate (optional, requires token config)
     * @param string|null $requestLogId The request log ID to update with validation results
     * @param string|null $requestHeaders JSON-encoded request headers (for drift payload)
     * @param string|null $responseHeaders JSON-encoded response headers (for drift payload)
     */
    public function validate(
        ApiToken $token,
        string $targetHost,
        string $path,
        string $method,
        string $responseBody,
        ?string $requestBody = null,
        ?string $requestLogId = null,
        ?string $requestHeaders = null,
        ?string $responseHeaders = null,
    ): void;
}
