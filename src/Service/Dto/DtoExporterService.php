<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Entity\ApiSchema;
use App\Entity\GeneratedDto;
use App\Repository\GeneratedDtoRepository;
use App\ValueObject\DtoGeneratorConfig;
use App\ValueObject\ExportOptions;
use App\ValueObject\ExportResult;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Service for exporting generated DTOs to the filesystem.
 */
final class DtoExporterService implements DtoExporterServiceInterface
{
    private const GITIGNORE_CONTENT = <<<'GITIGNORE'
# Auto-generated DTOs - do not commit
*
!.gitignore
GITIGNORE;

    public function __construct(
        private readonly GeneratedDtoRepository $repository,
        private readonly DtoGeneratorConfig $config,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function exportDto(
        GeneratedDto $dto,
        ?string $outputDir = null,
        ?ExportOptions $options = null,
    ): ExportResult {
        $outputDir ??= $this->config->outputDirectory;
        $options ??= ExportOptions::default();

        $filePath = $this->getFilePath($dto, $outputDir);
        $phpCode = $this->addFileHeader($dto);

        return $this->writeFile($filePath, $phpCode, $options);
    }

    public function exportBySchema(
        ApiSchema $schema,
        ?string $outputDir = null,
        ?ExportOptions $options = null,
    ): ExportResult {
        $dto = $this->repository->findCurrentBySchema($schema);

        if ($dto === null) {
            return ExportResult::error(
                sprintf('No DTO found for schema %s', $schema->getId()->toRfc4122())
            );
        }

        return $this->exportDto($dto, $outputDir, $options);
    }

    public function exportAll(
        ?string $outputDir = null,
        ?ExportOptions $options = null,
    ): ExportResult {
        $outputDir ??= $this->config->outputDirectory;
        $options ??= ExportOptions::default();

        $dtos = $this->repository->findWithFilters([], limit: 10000);

        if ($dtos === []) {
            return ExportResult::error('No DTOs found to export');
        }

        $result = new ExportResult(dryRun: $options->dryRun);

        foreach ($dtos as $dto) {
            $result = $result->merge($this->exportDto($dto, $outputDir, $options));
        }

        return $result;
    }

    public function exportBundled(
        array $dtos,
        string $filename,
        ?string $outputDir = null,
        ?ExportOptions $options = null,
    ): ExportResult {
        $outputDir ??= $this->config->outputDirectory;
        $options ??= ExportOptions::default();

        if ($dtos === []) {
            return ExportResult::error('No DTOs provided for bundled export');
        }

        $bundledCode = $this->createBundledCode($dtos);
        $filePath = rtrim($outputDir, '/') . '/' . $filename;

        return $this->writeFile($filePath, $bundledCode, $options);
    }

    public function getFilePath(GeneratedDto $dto, string $outputDir): string
    {
        $namespace = $dto->getNamespace();
        $className = $dto->getClassName();

        $baseNamespace = $this->config->defaultNamespace;
        $relativePath = '';

        if (str_starts_with($namespace, $baseNamespace)) {
            $relativePath = substr($namespace, strlen($baseNamespace));
            $relativePath = ltrim($relativePath, '\\');
        } else {
            $relativePath = $namespace;
        }

        $directoryPath = str_replace('\\', '/', $relativePath);
        $directoryPath = trim($directoryPath, '/');

        if ($directoryPath !== '') {
            return rtrim($outputDir, '/') . '/' . $directoryPath . '/' . $className . '.php';
        }

        return rtrim($outputDir, '/') . '/' . $className . '.php';
    }

    public function createGitignore(string $outputDir): ExportResult
    {
        $filePath = rtrim($outputDir, '/') . '/.gitignore';

        if ($this->filesystem->exists($filePath)) {
            return ExportResult::skipped($filePath);
        }

        try {
            $this->filesystem->mkdir($outputDir);
            $this->filesystem->dumpFile($filePath, self::GITIGNORE_CONTENT);

            return new ExportResult(
                filesWritten: [$filePath],
                bytesWritten: strlen(self::GITIGNORE_CONTENT),
            );
        } catch (\Throwable $e) {
            return ExportResult::error(
                sprintf('Failed to create .gitignore: %s', $e->getMessage())
            );
        }
    }

    private function addFileHeader(GeneratedDto $dto): string
    {
        $schema = $dto->getSchema();
        $header = $this->generateHeader($dto, $schema);
        $phpCode = $dto->getPhpCode();

        if (str_starts_with($phpCode, '<?php')) {
            $phpCode = substr($phpCode, 5);
            $phpCode = ltrim($phpCode);
        }

        return "<?php\n" . $header . "\n" . $phpCode;
    }

    private function generateHeader(GeneratedDto $dto, ApiSchema $schema): string
    {
        $timestamp = $dto->getCreatedAt()->format('c');
        $schemaPath = $schema->getEndpointPath();
        $schemaMethod = $schema->getHttpMethod();
        $schemaType = $schema->getSchemaType()->value;
        $schemaId = $schema->getId()->toRfc4122();
        $version = $dto->getVersion();

        return <<<HEADER
/**
 * AUTO-GENERATED FILE - DO NOT EDIT
 *
 * Generated by Sentinel PHP DTO Generator
 * Generated at: {$timestamp}
 * Schema: {$schemaPath} ({$schemaMethod} {$schemaType})
 * Schema ID: {$schemaId}
 * Version: {$version}
 */

HEADER;
    }

    /**
     * @param list<GeneratedDto> $dtos
     */
    private function createBundledCode(array $dtos): string
    {
        $timestamp = (new \DateTimeImmutable())->format('c');
        $count = count($dtos);

        $header = <<<HEADER
<?php
/**
 * AUTO-GENERATED FILE - DO NOT EDIT
 *
 * Bundled DTOs generated by Sentinel PHP DTO Generator
 * Generated at: {$timestamp}
 * Contains: {$count} DTO class(es)
 */


HEADER;

        $classes = [];
        foreach ($dtos as $dto) {
            $phpCode = $dto->getPhpCode();

            if (str_starts_with($phpCode, '<?php')) {
                $phpCode = substr($phpCode, 5);
                $phpCode = ltrim($phpCode);
            }

            $classes[] = $phpCode;
        }

        return $header . implode("\n\n", $classes);
    }

    private function writeFile(string $filePath, string $content, ExportOptions $options): ExportResult
    {
        if ($options->dryRun) {
            return ExportResult::dryRunResult($filePath, strlen($content));
        }

        try {
            $directory = dirname($filePath);
            $backupsCreated = [];

            if ($options->createDirectories && !$this->filesystem->exists($directory)) {
                $this->filesystem->mkdir($directory);
            }

            if ($this->filesystem->exists($filePath)) {
                if (!$options->overwrite) {
                    return ExportResult::skipped($filePath);
                }

                if ($options->backup) {
                    $backupPath = $filePath . '.bak';
                    $this->filesystem->copy($filePath, $backupPath, true);
                    $backupsCreated[] = $backupPath;
                }
            }

            $tempFile = $filePath . '.tmp.' . bin2hex(random_bytes(4));
            $this->filesystem->dumpFile($tempFile, $content);
            $this->filesystem->chmod($tempFile, $options->fileMode);
            $this->filesystem->rename($tempFile, $filePath, true);

            return new ExportResult(
                filesWritten: [$filePath],
                backupsCreated: $backupsCreated,
                bytesWritten: strlen($content),
            );
        } catch (\Throwable $e) {
            return ExportResult::error(
                sprintf('Failed to write %s: %s', $filePath, $e->getMessage())
            );
        }
    }
}
