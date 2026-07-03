<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SchemaLearnMessage
{
    public function __construct(
        public string $tokenId,
        public string $targetHost,
        public string $path,
        public string $method,
        public string $responseBody,
    ) {
    }
}
