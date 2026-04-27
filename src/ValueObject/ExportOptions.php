<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Options for DTO file export operations.
 */
final readonly class ExportOptions
{
    public function __construct(
        public bool $dryRun = false,
        public bool $backup = true,
        public bool $overwrite = true,
        public bool $createDirectories = true,
        public int $fileMode = 0644,
    ) {
    }

    public static function dryRun(): self
    {
        return new self(dryRun: true);
    }

    public static function default(): self
    {
        return new self();
    }

    public static function noBackup(): self
    {
        return new self(backup: false);
    }

    public function withDryRun(bool $dryRun): self
    {
        return new self(
            dryRun: $dryRun,
            backup: $this->backup,
            overwrite: $this->overwrite,
            createDirectories: $this->createDirectories,
            fileMode: $this->fileMode,
        );
    }

    public function withBackup(bool $backup): self
    {
        return new self(
            dryRun: $this->dryRun,
            backup: $backup,
            overwrite: $this->overwrite,
            createDirectories: $this->createDirectories,
            fileMode: $this->fileMode,
        );
    }

    public function withOverwrite(bool $overwrite): self
    {
        return new self(
            dryRun: $this->dryRun,
            backup: $this->backup,
            overwrite: $overwrite,
            createDirectories: $this->createDirectories,
            fileMode: $this->fileMode,
        );
    }
}
