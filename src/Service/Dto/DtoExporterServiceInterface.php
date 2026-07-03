<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Entity\ApiSchema;
use App\Entity\GeneratedDto;
use App\ValueObject\ExportOptions;
use App\ValueObject\ExportResult;

/**
 * Interface for exporting generated DTOs to the filesystem.
 */
interface DtoExporterServiceInterface
{
    /**
     * Export a single DTO to the filesystem.
     */
    public function exportDto(
        GeneratedDto $dto,
        ?string $outputDir = null,
        ?ExportOptions $options = null,
    ): ExportResult;

    /**
     * Export the current DTO for a schema.
     */
    public function exportBySchema(
        ApiSchema $schema,
        ?string $outputDir = null,
        ?ExportOptions $options = null,
    ): ExportResult;

    /**
     * Export all current DTOs to the filesystem.
     */
    public function exportAll(
        ?string $outputDir = null,
        ?ExportOptions $options = null,
    ): ExportResult;

    /**
     * Export multiple DTOs bundled into a single file.
     *
     * @param list<GeneratedDto> $dtos
     */
    public function exportBundled(
        array $dtos,
        string $filename,
        ?string $outputDir = null,
        ?ExportOptions $options = null,
    ): ExportResult;

    /**
     * Generate the file path for a DTO based on its namespace.
     */
    public function getFilePath(GeneratedDto $dto, string $outputDir): string;

    /**
     * Create a .gitignore file in the output directory.
     */
    public function createGitignore(string $outputDir): ExportResult;
}
