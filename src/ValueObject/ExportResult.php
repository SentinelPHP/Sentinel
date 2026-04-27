<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Result of a DTO export operation.
 */
final readonly class ExportResult
{
    /**
     * @param list<string> $filesWritten Absolute paths of files written
     * @param list<string> $filesSkipped Absolute paths of files skipped (already up-to-date)
     * @param list<string> $backupsCreated Absolute paths of backup files created
     * @param list<string> $errors Error messages for failed operations
     */
    public function __construct(
        public array $filesWritten = [],
        public array $filesSkipped = [],
        public array $backupsCreated = [],
        public array $errors = [],
        public int $bytesWritten = 0,
        public bool $dryRun = false,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->errors === [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function getFileCount(): int
    {
        return count($this->filesWritten);
    }

    public function getSkippedCount(): int
    {
        return count($this->filesSkipped);
    }

    public function merge(self $other): self
    {
        return new self(
            filesWritten: [...$this->filesWritten, ...$other->filesWritten],
            filesSkipped: [...$this->filesSkipped, ...$other->filesSkipped],
            backupsCreated: [...$this->backupsCreated, ...$other->backupsCreated],
            errors: [...$this->errors, ...$other->errors],
            bytesWritten: $this->bytesWritten + $other->bytesWritten,
            dryRun: $this->dryRun || $other->dryRun,
        );
    }

    public static function error(string $message): self
    {
        return new self(errors: [$message]);
    }

    public static function skipped(string $filePath): self
    {
        return new self(filesSkipped: [$filePath]);
    }

    public static function dryRunResult(string $filePath, int $bytes): self
    {
        return new self(
            filesWritten: [$filePath],
            bytesWritten: $bytes,
            dryRun: true,
        );
    }
}
