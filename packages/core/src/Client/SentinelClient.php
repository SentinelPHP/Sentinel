<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Client;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SentinelPHP\Core\SentinelInterceptor;

/**
 * PSR-18 HTTP client wrapper that intercepts all requests and responses.
 */
final class SentinelClient implements SentinelClientInterface
{
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly SentinelInterceptor $interceptor,
        private readonly ?IdGeneratorInterface $idGenerator = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $startTime = hrtime(true);

        $response = $this->inner->sendRequest($request);

        $latencyMs = (hrtime(true) - $startTime) / 1_000_000;

        /** @var array<string, array<int, string>> $requestHeaders */
        $requestHeaders = $request->getHeaders();
        /** @var array<string, array<int, string>> $responseHeaders */
        $responseHeaders = $response->getHeaders();

        $this->interceptor->intercept(
            method: $request->getMethod(),
            url: (string) $request->getUri(),
            statusCode: $response->getStatusCode(),
            latencyMs: $latencyMs,
            requestHeaders: $this->flattenHeaders($requestHeaders),
            requestBody: (string) $request->getBody(),
            responseHeaders: $this->flattenHeaders($responseHeaders),
            responseBody: (string) $response->getBody(),
            id: $this->idGenerator?->generate(),
        );

        // Rewind response body so it can be read again by the caller
        $response->getBody()->rewind();

        return $response;
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array<string, string|list<string>>
     */
    private function flattenHeaders(array $headers): array
    {
        $flattened = [];

        foreach ($headers as $name => $values) {
            /** @var list<string> $valueList */
            $valueList = array_values($values);
            $flattened[$name] = count($valueList) === 1 ? $valueList[0] : $valueList;
        }

        return $flattened;
    }
}
