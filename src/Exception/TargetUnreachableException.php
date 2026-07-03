<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

final class TargetUnreachableException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?string $targetHost = null,
        public readonly ?string $targetUrl = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function connectionFailed(string $host, int $port, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to connect to %s:%d - %s', $host, $port, $reason),
            $host,
            sprintf('%s:%d', $host, $port),
            $previous
        );
    }

    public static function timeout(string $url, int $timeoutSeconds, ?Throwable $previous = null): self
    {
        $host = parse_url($url, PHP_URL_HOST);

        return new self(
            sprintf('Request to %s timed out after %d seconds', $url, $timeoutSeconds),
            is_string($host) ? $host : null,
            $url,
            $previous
        );
    }

    public static function dnsResolutionFailed(string $host, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('DNS resolution failed for host: %s', $host),
            $host,
            null,
            $previous
        );
    }

    public static function requestFailed(string $url, string $reason, ?Throwable $previous = null): self
    {
        $host = parse_url($url, PHP_URL_HOST);

        return new self(
            sprintf('Request to %s failed - %s', $url, $reason),
            is_string($host) ? $host : null,
            $url,
            $previous
        );
    }

    public function getHttpStatusCode(): int
    {
        return 502;
    }
}
