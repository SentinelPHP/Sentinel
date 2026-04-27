<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class HttpClientException extends RuntimeException
{
    public static function connectionFailed(string $host, int $port, string $reason): self
    {
        return new self(sprintf(
            'Failed to connect to %s:%d - %s',
            $host,
            $port,
            $reason
        ));
    }

    public static function requestFailed(string $url, string $reason): self
    {
        return new self(sprintf(
            'Request to %s failed - %s',
            $url,
            $reason
        ));
    }

    public static function invalidUrl(string $url): self
    {
        return new self(sprintf('Invalid URL: %s', $url));
    }
}
