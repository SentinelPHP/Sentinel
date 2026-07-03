<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\LogLevel;

final readonly class RequestLogMessage
{
    public function __construct(
        public string $requestLogId,
        public ?string $tokenId,
        public string $targetHost,
        public string $requestMethod,
        public string $requestPath,
        public int $responseStatusCode,
        public int $latencyMs,
        public LogLevel $logLevel,
        public ?string $requestHeaders = null,
        public ?string $requestBody = null,
        public ?string $responseHeaders = null,
        public ?string $responseBody = null,
    ) {
    }
}
