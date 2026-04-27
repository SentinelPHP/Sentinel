<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Storage;

use SentinelPHP\Core\Record\ApiCallRecord;

/**
 * Storage implementation that delegates to multiple storage backends.
 */
final class ChainStorage implements StorageInterface
{
    /**
     * @var list<StorageInterface>
     */
    private array $storages;

    /**
     * @param StorageInterface ...$storages Storage backends to chain
     */
    public function __construct(StorageInterface ...$storages)
    {
        $this->storages = array_values($storages);
    }

    public function store(ApiCallRecord $record): void
    {
        $errors = [];

        foreach ($this->storages as $storage) {
            try {
                $storage->store($record);
            } catch (StorageException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw StorageException::storeFailed(implode('; ', $errors));
        }
    }

    public function add(StorageInterface $storage): void
    {
        $this->storages[] = $storage;
    }
}
