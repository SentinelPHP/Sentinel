<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Storage;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SentinelPHP\Core\Record\ApiCallRecord;

/**
 * Storage implementation that sends API call records to a SentinelPHP server.
 *
 * Usage:
 * ```php
 * $storage = new SentinelHttpStorage(
 *     httpClient: $guzzleClient,
 *     requestFactory: $psr17Factory,
 *     streamFactory: $psr17Factory,
 *     baseUrl: 'https://sentinel.example.com',
 *     apiToken: 'your-api-token',
 * );
 * ```
 */
final class SentinelHttpStorage implements StorageInterface
{
    private const string INGEST_PATH = '/api/ingest';

    /**
     * @param ClientInterface $httpClient PSR-18 HTTP client
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     * @param string $baseUrl Base URL of the SentinelPHP server (e.g., 'https://sentinel.example.com')
     * @param string $apiToken API token for authentication
     * @param bool $throwOnError Whether to throw exceptions on HTTP errors (default: true)
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUrl,
        private readonly string $apiToken,
        private readonly bool $throwOnError = true,
    ) {
    }

    public function store(ApiCallRecord $record): void
    {
        $url = rtrim($this->baseUrl, '/') . self::INGEST_PATH;

        $body = json_encode($record->toArray(), JSON_THROW_ON_ERROR);
        $stream = $this->streamFactory->createStream($body);

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->apiToken)
            ->withBody($stream);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            if ($this->throwOnError) {
                throw StorageException::storeFailed('HTTP request failed: ' . $e->getMessage(), $e);
            }
            return;
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 400 && $this->throwOnError) {
            throw StorageException::storeFailed(
                sprintf('SentinelPHP server returned HTTP %d', $statusCode)
            );
        }
    }
}
