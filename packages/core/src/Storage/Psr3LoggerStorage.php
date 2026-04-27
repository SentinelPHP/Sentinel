<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Storage;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use SentinelPHP\Core\Record\ApiCallRecord;
use Throwable;

/**
 * Storage implementation that logs API calls to a PSR-3 logger.
 */
final class Psr3LoggerStorage implements StorageInterface
{
    /**
     * @param LoggerInterface $logger The PSR-3 logger to use
     * @param string $logLevel The log level to use (default: info)
     * @param bool $includeBody Whether to include request/response bodies in logs
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $logLevel = LogLevel::INFO,
        private readonly bool $includeBody = true,
    ) {
    }

    public function store(ApiCallRecord $record): void
    {
        try {
            $context = [
                'id' => $record->id,
                'method' => $record->method,
                'url' => $record->url,
                'status_code' => $record->statusCode,
                'latency_ms' => $record->latencyMs,
                'timestamp' => $record->timestamp->format(\DateTimeImmutable::ATOM),
            ];

            if ($this->includeBody) {
                $context['request_body'] = $record->requestBody;
                $context['response_body'] = $record->responseBody;
            }

            if ($record->generatedSchema !== null) {
                $context['schema'] = $record->generatedSchema;
            }

            $this->logger->log(
                $this->logLevel,
                sprintf('API Call: %s %s -> %d', $record->method, $record->url, $record->statusCode),
                $context
            );
        } catch (Throwable $e) {
            throw StorageException::storeFailed($e->getMessage(), $e);
        }
    }
}
