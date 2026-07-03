<?php

declare(strict_types=1);

namespace SentinelPHP\Drift\Diff;

final readonly class DiffResult
{
    /**
     * @param list<DiffEntry> $added Fields that were added
     * @param list<DiffEntry> $removed Fields that were removed
     * @param list<DiffEntry> $changed Fields that were changed
     */
    public function __construct(
        public array $added = [],
        public array $removed = [],
        public array $changed = [],
    ) {
    }

    public function hasDifferences(): bool
    {
        return $this->added !== [] || $this->removed !== [] || $this->changed !== [];
    }

    public function getTotalCount(): int
    {
        return count($this->added) + count($this->removed) + count($this->changed);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'added' => array_map(static fn (DiffEntry $e) => $e->toArray(), $this->added),
            'removed' => array_map(static fn (DiffEntry $e) => $e->toArray(), $this->removed),
            'changed' => array_map(static fn (DiffEntry $e) => $e->toArray(), $this->changed),
            'hasDifferences' => $this->hasDifferences(),
            'totalCount' => $this->getTotalCount(),
        ];
    }
}
