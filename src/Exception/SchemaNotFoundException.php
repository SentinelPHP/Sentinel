<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class SchemaNotFoundException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?string $tokenId = null,
        public readonly ?string $host = null,
        public readonly ?string $path = null,
        public readonly ?string $method = null,
    ) {
        parent::__construct($message);
    }

    public static function forEndpoint(string $tokenId, string $host, string $path, string $method): self
    {
        return new self(
            sprintf(
                'No master schema found for endpoint: %s %s%s (token: %s)',
                strtoupper($method),
                $host,
                $path,
                $tokenId
            ),
            $tokenId,
            $host,
            $path,
            $method
        );
    }

    public static function forSchemaId(string $schemaId): self
    {
        return new self(
            sprintf('Schema not found: %s', $schemaId)
        );
    }

    public function getHttpStatusCode(): int
    {
        return 404;
    }
}
