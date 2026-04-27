<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Record;

use DateTimeImmutable;

/**
 * Immutable record of an intercepted API call.
 */
final readonly class ApiCallRecord
{
    /**
     * @param array<string, string|list<string>> $requestHeaders
     * @param array<string, string|list<string>> $responseHeaders
     * @param array<string, mixed>|null $generatedSchema
     */
    public function __construct(
        public string $method,
        public string $url,
        public int $statusCode,
        public float $latencyMs,
        public DateTimeImmutable $timestamp,
        public array $requestHeaders = [],
        public ?string $requestBody = null,
        public array $responseHeaders = [],
        public ?string $responseBody = null,
        public ?array $generatedSchema = null,
        public ?string $id = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $method = $data['method'] ?? 'GET';
        $url = $data['url'] ?? '';
        $statusCode = $data['statusCode'] ?? 0;
        $latencyMs = $data['latencyMs'] ?? 0.0;
        $timestamp = $data['timestamp'] ?? 'now';
        $requestBody = $data['requestBody'] ?? null;
        $responseBody = $data['responseBody'] ?? null;
        $id = $data['id'] ?? null;

        /** @var array<string, string|list<string>> $requestHeaders */
        $requestHeaders = is_array($data['requestHeaders'] ?? null) ? $data['requestHeaders'] : [];
        /** @var array<string, string|list<string>> $responseHeaders */
        $responseHeaders = is_array($data['responseHeaders'] ?? null) ? $data['responseHeaders'] : [];
        /** @var array<string, mixed>|null $generatedSchema */
        $generatedSchema = is_array($data['generatedSchema'] ?? null) ? $data['generatedSchema'] : null;

        return new self(
            method: is_string($method) ? $method : 'GET',
            url: is_string($url) ? $url : '',
            statusCode: is_int($statusCode) ? $statusCode : (is_numeric($statusCode) ? (int) $statusCode : 0),
            latencyMs: is_float($latencyMs) ? $latencyMs : (is_numeric($latencyMs) ? (float) $latencyMs : 0.0),
            timestamp: $timestamp instanceof DateTimeImmutable
                ? $timestamp
                : new DateTimeImmutable(is_string($timestamp) ? $timestamp : 'now'),
            requestHeaders: $requestHeaders,
            requestBody: is_string($requestBody) ? $requestBody : null,
            responseHeaders: $responseHeaders,
            responseBody: is_string($responseBody) ? $responseBody : null,
            generatedSchema: $generatedSchema,
            id: is_string($id) ? $id : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'url' => $this->url,
            'statusCode' => $this->statusCode,
            'latencyMs' => $this->latencyMs,
            'timestamp' => $this->timestamp->format(DateTimeImmutable::ATOM),
            'requestHeaders' => $this->requestHeaders,
            'requestBody' => $this->requestBody,
            'responseHeaders' => $this->responseHeaders,
            'responseBody' => $this->responseBody,
            'generatedSchema' => $this->generatedSchema,
        ];
    }
}
