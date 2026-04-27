<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Storage;

use SentinelPHP\Core\Record\ApiCallRecord;

/**
 * No-op storage implementation for testing or when storage is disabled.
 */
final class NullStorage implements StorageInterface
{
    public function store(ApiCallRecord $record): void
    {
        // Intentionally empty - discards all records
    }
}
