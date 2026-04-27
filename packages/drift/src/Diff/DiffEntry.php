<?php

declare(strict_types=1);

namespace SentinelPHP\Drift\Diff;

final readonly class DiffEntry
{
    public function __construct(
        public string $path,
        public mixed $expectedValue = null,
        public mixed $actualValue = null,
        public string $type = 'changed',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'expectedValue' => $this->expectedValue,
            'actualValue' => $this->actualValue,
            'type' => $this->type,
        ];
    }
}
