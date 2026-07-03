<?php

declare(strict_types=1);

namespace App\Http;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param string|null $resolvedIp Pre-resolved IP address to use instead of DNS lookup (prevents DNS rebinding)
     *
     * @throws HttpClientException
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $resolvedIp = null,
    ): HttpResponse;
}
