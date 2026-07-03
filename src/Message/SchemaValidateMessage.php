<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SchemaValidateMessage
{
    public function __construct(
        public string $tokenId,
        public string $targetHost,
        public string $path,
        public string $method,
        public string $responseBody,
        public ?string $requestBody = null,
        public ?string $requestLogId = null,
        public ?string $requestHeaders = null,
        public ?string $responseHeaders = null,
    ) {
    }
}
