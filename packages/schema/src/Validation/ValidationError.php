<?php

declare(strict_types=1);

namespace SentinelPHP\Schema\Validation;

/**
 * Represents a single validation error.
 */
final readonly class ValidationError
{
    public function __construct(
        public string $path,
        public string $message,
        public string $keyword,
        public mixed $expected = null,
        public mixed $actual = null,
    ) {
    }

    /**
     * @return array{path: string, message: string, keyword: string, expected: mixed, actual: mixed}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'message' => $this->message,
            'keyword' => $this->keyword,
            'expected' => $this->expected,
            'actual' => $this->actual,
        ];
    }
}
