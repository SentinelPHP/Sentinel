<?php

declare(strict_types=1);

namespace SentinelPHP\Core;

use DateTimeImmutable;
use SentinelPHP\Core\Config\InterceptorConfig;
use SentinelPHP\Core\Record\ApiCallRecord;
use SentinelPHP\Core\Storage\StorageInterface;
use SentinelPHP\Redact\PiiRedactorInterface;
use SentinelPHP\Schema\GeneratorInterface;

/**
 * Main orchestrator for intercepting and processing API calls.
 *
 * Handles PII redaction, schema generation, and storage delegation.
 */
final class SentinelInterceptor
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly InterceptorConfig $config = new InterceptorConfig(),
        private readonly ?PiiRedactorInterface $redactor = null,
        private readonly ?GeneratorInterface $schemaGenerator = null,
    ) {
    }

    /**
     * Process and store an intercepted API call.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param int $statusCode Response status code
     * @param float $latencyMs Request latency in milliseconds
     * @param array<string, string|list<string>> $requestHeaders
     * @param string|null $requestBody
     * @param array<string, string|list<string>> $responseHeaders
     * @param string|null $responseBody
     * @param string|null $id Optional unique identifier for the record
     */
    public function intercept(
        string $method,
        string $url,
        int $statusCode,
        float $latencyMs,
        array $requestHeaders = [],
        ?string $requestBody = null,
        array $responseHeaders = [],
        ?string $responseBody = null,
        ?string $id = null,
    ): ApiCallRecord {
        $processedRequestBody = $this->processBody($requestBody);
        $processedResponseBody = $this->processBody($responseBody);
        $schema = $this->generateSchema($responseBody);

        $record = new ApiCallRecord(
            method: $method,
            url: $url,
            statusCode: $statusCode,
            latencyMs: $latencyMs,
            timestamp: new DateTimeImmutable(),
            requestHeaders: $this->config->captureHeaders ? $requestHeaders : [],
            requestBody: $this->config->captureRequestBody ? $processedRequestBody : null,
            responseHeaders: $this->config->captureHeaders ? $responseHeaders : [],
            responseBody: $this->config->captureResponseBody ? $processedResponseBody : null,
            generatedSchema: $schema,
            id: $id,
        );

        $this->storage->store($record);

        return $record;
    }

    private function processBody(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        if (!$this->config->redactPii || $this->redactor === null) {
            return $body;
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $this->redactor->redactString($body);
        }

        /** @var array<mixed>|string $redacted */
        $redacted = $this->redactor->redact(
            $decoded,
            $this->config->redactFieldPaths !== [] ? $this->config->redactFieldPaths : null
        );

        if (is_string($redacted)) {
            return $redacted;
        }

        $encoded = json_encode($redacted, JSON_THROW_ON_ERROR);

        return $encoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function generateSchema(?string $body): ?array
    {
        if (!$this->config->generateSchemas || $this->schemaGenerator === null) {
            return null;
        }

        if ($body === null || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        /** @var array<int<0, max>|string, mixed> $payload */
        $payload = $decoded;

        return $this->schemaGenerator->generate($payload);
    }

    public function getConfig(): InterceptorConfig
    {
        return $this->config;
    }

    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }
}
