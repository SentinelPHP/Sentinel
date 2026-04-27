<?php

declare(strict_types=1);

namespace App\Storage;

use App\Entity\ApiToken;
use App\Enum\LogLevel;
use App\Message\RequestLogMessage;
use App\Message\SchemaLearnMessage;
use App\Message\SchemaValidateMessage;
use SentinelPHP\Core\Record\ApiCallRecord;
use SentinelPHP\Core\Storage\StorageInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Storage implementation that dispatches Symfony Messenger messages
 * for request logging, schema learning, and schema validation.
 *
 * This bridges the Core package's StorageInterface with the application's
 * message-based architecture.
 */
final class MessengerStorage implements StorageInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ApiToken $token,
        private readonly LogLevel $defaultLogLevel = LogLevel::MetadataOnly,
    ) {
    }

    public function store(ApiCallRecord $record): void
    {
        $host = $this->extractHost($record->url);
        $path = $this->extractPath($record->url);

        $this->dispatchRequestLog($record, $host, $path);
        $this->dispatchSchemaLearning($record, $host, $path);
        $this->dispatchSchemaValidation($record, $host, $path);
    }

    private function dispatchRequestLog(ApiCallRecord $record, string $host, string $path): void
    {
        $logLevel = $this->token->getLogLevel() ?? $this->defaultLogLevel;

        if ($logLevel->shouldSkipLogging()) {
            return;
        }

        $this->messageBus->dispatch(new RequestLogMessage(
            requestLogId: $record->id ?? $this->generateId(),
            tokenId: $this->token->getId()->toRfc4122(),
            targetHost: $host,
            requestMethod: $record->method,
            requestPath: $path,
            responseStatusCode: $record->statusCode,
            latencyMs: (int) $record->latencyMs,
            logLevel: $logLevel,
            requestHeaders: $record->requestHeaders !== [] ? json_encode($record->requestHeaders, JSON_THROW_ON_ERROR) : null,
            requestBody: $record->requestBody,
            responseHeaders: $record->responseHeaders !== [] ? json_encode($record->responseHeaders, JSON_THROW_ON_ERROR) : null,
            responseBody: $record->responseBody,
        ));
    }

    private function dispatchSchemaLearning(ApiCallRecord $record, string $host, string $path): void
    {
        if ($record->responseBody === null || $record->responseBody === '') {
            return;
        }

        $this->messageBus->dispatch(new SchemaLearnMessage(
            tokenId: $this->token->getId()->toRfc4122(),
            targetHost: $host,
            path: $path,
            method: $record->method,
            responseBody: $record->responseBody,
        ));
    }

    private function dispatchSchemaValidation(ApiCallRecord $record, string $host, string $path): void
    {
        if ($record->responseBody === null || $record->responseBody === '') {
            return;
        }

        $this->messageBus->dispatch(new SchemaValidateMessage(
            tokenId: $this->token->getId()->toRfc4122(),
            targetHost: $host,
            path: $path,
            method: $record->method,
            responseBody: $record->responseBody,
            requestBody: $record->requestBody,
            requestLogId: $record->id,
            requestHeaders: $record->requestHeaders !== [] ? json_encode($record->requestHeaders, JSON_THROW_ON_ERROR) : null,
            responseHeaders: $record->responseHeaders !== [] ? json_encode($record->responseHeaders, JSON_THROW_ON_ERROR) : null,
        ));
    }

    private function extractHost(string $url): string
    {
        $parsed = parse_url($url);
        return $parsed['host'] ?? '';
    }

    private function extractPath(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';
        if (isset($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }
        return $path;
    }

    private function generateId(): string
    {
        return \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
    }
}
