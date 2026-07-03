<?php

declare(strict_types=1);

namespace App\Http;

use Swoole\Coroutine\Http\Client;

final class SwooleHttpClient implements HttpClientInterface
{
    private const float DEFAULT_TIMEOUT = 30.0;
    private const float DEFAULT_CONNECT_TIMEOUT = 10.0;

    public function __construct(
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
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['host'])) {
            throw HttpClientException::invalidUrl($url);
        }

        $ssl = ($parsed['scheme'] ?? 'http') === 'https';
        $host = $parsed['host'];
        $port = $parsed['port'] ?? ($ssl ? 443 : 80);

        $connectHost = $resolvedIp ?? $host;
        $client = new Client($connectHost, $port, $ssl);

        if ($resolvedIp !== null) {
            $headers['Host'] = $host;
        }
        $client->set([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ]);

        $client->setMethod($method);

        if ($headers !== []) {
            $client->setHeaders($headers);
        }

        if ($body !== null) {
            $client->setData($body);
        }

        $path = $this->buildPath($parsed);

        $success = $client->execute($path);

        if (!$success) {
            /** @var int $errCode */
            $errCode = $client->errCode;
            /** @var string $errMsg */
            $errMsg = $client->errMsg ?: "Error code: {$errCode}";
            $client->close();

            throw HttpClientException::connectionFailed($host, $port, $errMsg);
        }

        /** @var int $statusCode */
        $statusCode = $client->getStatusCode();

        if ($statusCode < 0) {
            /** @var string $errMsg */
            $errMsg = $client->errMsg ?: 'Unknown error';
            $client->close();

            throw HttpClientException::requestFailed($url, $errMsg);
        }

        /** @var array<string, mixed> $rawHeaders */
        $rawHeaders = $client->getHeaders() ?? [];
        $responseHeaders = $this->normalizeHeaders($rawHeaders);
        /** @var string $responseBody */
        $responseBody = $client->getBody() ?? '';

        $client->close();

        return new HttpResponse($statusCode, $responseHeaders, $responseBody);
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function buildPath(array $parsed): string
    {
        /** @var string $path */
        $path = $parsed['path'] ?? '/';

        if (isset($parsed['query'])) {
            /** @var string $query */
            $query = $parsed['query'];
            $path .= '?' . $query;
        }

        if (isset($parsed['fragment'])) {
            /** @var string $fragment */
            $fragment = $parsed['fragment'];
            $path .= '#' . $fragment;
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $first = $value[0] ?? '';
                $normalized[$name] = is_scalar($first) ? (string) $first : '';
            } else {
                $normalized[$name] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $normalized;
    }
}
