<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Response;

/**
 * Encapsulates the result of a proxy request for logging purposes.
 */
final readonly class ProxyResult
{
    /**
     * @param array<string, string> $requestHeaders
     * @param array<string, list<string>|string>|null $responseHeaders
     */
    public function __construct(
        public Response $response,
        public int $statusCode,
        public array $requestHeaders,
        public string $requestBody,
        public ?array $responseHeaders,
        public ?string $responseBody,
    ) {
    }
}
