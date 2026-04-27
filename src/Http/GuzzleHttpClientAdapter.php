<?php

declare(strict_types=1);

namespace App\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;

final class GuzzleHttpClientAdapter implements HttpClientInterface
{
    private const float DEFAULT_TIMEOUT = 30.0;
    private const float DEFAULT_CONNECT_TIMEOUT = 10.0;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        private readonly float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
    ) {
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $resolvedIp = null,
    ): HttpResponse {
        $options = [
            'http_errors' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ];

        if ($resolvedIp !== null) {
            $parsed = parse_url($url);
            $host = $parsed['host'] ?? '';
            $port = $parsed['port'] ?? (($parsed['scheme'] ?? 'http') === 'https' ? 443 : 80);
            $options['curl'] = [
                CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolvedIp}"],
            ];
        }

        $request = new Request($method, $url, $headers, $body);

        try {
            $response = $this->client->send($request, $options);
        } catch (GuzzleException $e) {
            throw HttpClientException::requestFailed($url, $e->getMessage());
        }

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            /** @var string $headerName */
            $headerName = $name;
            $responseHeaders[$headerName] = $values[0] ?? '';
        }

        return new HttpResponse(
            $response->getStatusCode(),
            $responseHeaders,
            (string) $response->getBody()
        );
    }
}
