<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;

interface SchemaLearningServiceInterface
{
    /**
     * Learn schema from a response body during the proxy cycle.
     *
     * @param ApiToken $token The API token making the request
     * @param string $targetHost The target host (e.g., "api.example.com")
     * @param string $path The endpoint path (e.g., "/users/123")
     * @param string $method The HTTP method (e.g., "GET")
     * @param string $responseBody The raw response body (JSON string)
     */
    public function learn(
        ApiToken $token,
        string $targetHost,
        string $path,
        string $method,
        string $responseBody,
    ): void;
}
