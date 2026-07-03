<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Storage;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when storage operations fail.
 */
final class StorageException extends RuntimeException
{
    public static function storeFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to store API call record: %s', $reason),
            0,
            $previous
        );
    }
}
