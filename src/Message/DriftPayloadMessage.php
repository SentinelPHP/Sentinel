<?php

declare(strict_types=1);

namespace App\Message;

final readonly class DriftPayloadMessage
{
    public function __construct(
        public string $requestLogId,
        public ?string $requestBody = null,
        public ?string $responseBody = null,
        public ?string $requestHeaders = null,
        public ?string $responseHeaders = null,
    ) {
    }
}
