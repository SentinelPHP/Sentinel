<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Storage;

use SentinelPHP\Core\Record\ApiCallRecord;
use Throwable;

/**
 * Storage implementation that delegates to a user-provided callback.
 */
final class CallbackStorage implements StorageInterface
{
    /**
     * @var callable(ApiCallRecord): void
     */
    private $callback;

    /**
     * @param callable(ApiCallRecord): void $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function store(ApiCallRecord $record): void
    {
        try {
            ($this->callback)($record);
        } catch (Throwable $e) {
            throw StorageException::storeFailed($e->getMessage(), $e);
        }
    }
}
