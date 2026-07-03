<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Storage;

use SentinelPHP\Core\Record\ApiCallRecord;

/**
 * Interface for storing intercepted API call records.
 */
interface StorageInterface
{
    /**
     * Store an API call record.
     *
     * @throws StorageException If storage fails
     */
    public function store(ApiCallRecord $record): void;
}
