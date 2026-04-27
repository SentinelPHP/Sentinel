<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class InvalidTargetException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?string $targetUrl = null,
        public readonly ?string $validationError = null,
    ) {
        parent::__construct($message);
    }

    public static function missingHeader(string $headerName): self
    {
        return new self(
            sprintf('Missing required header: %s', $headerName),
            null,
            'missing_header'
        );
    }

    public static function malformedUrl(string $url): self
    {
        return new self(
            sprintf('Invalid target URL: %s', $url),
            $url,
            'malformed_url'
        );
    }

    public static function hostNotAllowed(string $url, string $host): self
    {
        return new self(
            sprintf('Target host is not allowed for this token: %s', $host),
            $url,
            'host_not_allowed'
        );
    }

    public static function privateIpBlocked(string $url, string $ip): self
    {
        return new self(
            sprintf('Target resolves to private/reserved IP address: %s', $ip),
            $url,
            'private_ip_blocked'
        );
    }

    public static function validationFailed(string $url, string $reason): self
    {
        return new self(
            sprintf('Invalid target URL: %s', $reason),
            $url,
            'validation_failed'
        );
    }

    public function getHttpStatusCode(): int
    {
        return match ($this->validationError) {
            'missing_header' => 400,
            default => 403,
        };
    }
}
