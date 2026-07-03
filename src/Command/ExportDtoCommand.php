<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ApiSchemaRepository;
use App\Repository\ApiTokenRepository;
use App\Repository\GeneratedDtoRepository;
use App\Service\Dto\DtoExporterServiceInterface;
use App\ValueObject\ExportOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'sentinel:dto:export',
    description: 'Export generated DTOs to filesystem',
)]
final class ExportDtoCommand extends Command
{
    private const FORMAT_SINGLE = 'single-file';
    private const FORMAT_BUNDLED = 'bundled';

    public function __construct(
        private readonly DtoExporterServiceInterface $dtoExporter,
        private readonly GeneratedDtoRepository $dtoRepository,
        private readonly ApiSchemaRepository $schemaRepository,
        private readonly ApiTokenRepository $tokenRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('schema-id', 's', InputOption::VALUE_REQUIRED, 'Export DTO for specific schema UUID')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Export all DTOs for a token')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Export all current DTOs')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Export format: single-file or bundled', self::FORMAT_SINGLE)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Override default output directory')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without writing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $schemaId */
        $schemaId = $input->getOption('schema-id');
        /** @var string|null $tokenFilter */
        $tokenFilter = $input->getOption('token');
        $exportAll = (bool) $input->getOption('all');
        /** @var string $format */
        $format = $input->getOption('format');
        $force = (bool) $input->getOption('force');
        /** @var string|null $outputDir */
        $outputDir = $input->getOption('output-dir');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!in_array($format, [self::FORMAT_SINGLE, self::FORMAT_BUNDLED], true)) {
            $io->error(sprintf(
                'Invalid format: %s. Valid values: %s, %s',
                $format,
                self::FORMAT_SINGLE,
                self::FORMAT_BUNDLED
            ));
            return Command::FAILURE;
        }

        $options = new ExportOptions(
            dryRun: $dryRun,
            backup: true,
            overwrite: $force,
        );

        if ($schemaId !== null) {
            return $this->exportBySchema($schemaId, $outputDir, $options, $io);
        }

        if ($tokenFilter !== null) {
            return $this->exportByToken($tokenFilter, $format, $outputDir, $options, $io);
        }

        if ($exportAll) {
            return $this->exportAll($format, $outputDir, $options, $io);
        }

        $io->error('Please specify --schema-id, --token, or --all.');
        return Command::FAILURE;
    }

    private function exportBySchema(
        string $schemaIdInput,
        ?string $outputDir,
        ExportOptions $options,
        SymfonyStyle $io,
    ): int {
        if (!Uuid::isValid($schemaIdInput)) {
            $io->error(sprintf('Invalid UUID format: %s', $schemaIdInput));
            return Command::FAILURE;
        }

        $schemaId = Uuid::fromString($schemaIdInput);
        $schema = $this->schemaRepository->find($schemaId);

        if ($schema === null) {
            $io->error(sprintf('Schema not found: %s', $schemaIdInput));
            return Command::FAILURE;
        }

        $result = $this->dtoExporter->exportBySchema($schema, $outputDir, $options);

        return $this->handleResult($result, $options, $io);
    }

    private function exportByToken(
        string $tokenFilter,
        string $format,
        ?string $outputDir,
        ExportOptions $options,
        SymfonyStyle $io,
    ): int {
        $tokenId = $this->resolveTokenId($tokenFilter);
        if ($tokenId === null) {
            $io->error(sprintf('Token not found: %s', $tokenFilter));
            return Command::FAILURE;
        }

        $dtos = $this->dtoRepository->findWithFilters(['tokenId' => $tokenId], limit: 10000);

        if ($dtos === []) {
            $io->info('No DTOs found for this token.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d DTO(s) to export.', count($dtos)));

        if ($format === self::FORMAT_BUNDLED) {
            $token = $this->tokenRepository->find($tokenId);
            $tokenName = $token?->getName() ?? 'unknown';
            $filename = sprintf('%s_dtos.php', $this->sanitizeFilename($tokenName));

            $result = $this->dtoExporter->exportBundled($dtos, $filename, $outputDir, $options);
            return $this->handleResult($result, $options, $io);
        }

        return $this->exportMultiple($dtos, $outputDir, $options, $io);
    }

    private function exportAll(
        string $format,
        ?string $outputDir,
        ExportOptions $options,
        SymfonyStyle $io,
    ): int {
        if ($format === self::FORMAT_BUNDLED) {
            $dtos = $this->dtoRepository->findWithFilters([], limit: 10000);

            if ($dtos === []) {
                $io->info('No DTOs found to export.');
                return Command::SUCCESS;
            }

            $io->info(sprintf('Exporting %d DTO(s) as bundled file.', count($dtos)));

            $result = $this->dtoExporter->exportBundled($dtos, 'all_dtos.php', $outputDir, $options);
            return $this->handleResult($result, $options, $io);
        }

        $result = $this->dtoExporter->exportAll($outputDir, $options);
        return $this->handleResult($result, $options, $io);
    }

    /**
     * @param list<\App\Entity\GeneratedDto> $dtos
     */
    private function exportMultiple(
        array $dtos,
        ?string $outputDir,
        ExportOptions $options,
        SymfonyStyle $io,
    ): int {
        $totalWritten = 0;
        $totalSkipped = 0;
        $errors = [];

        $io->progressStart(count($dtos));

        foreach ($dtos as $dto) {
            $result = $this->dtoExporter->exportDto($dto, $outputDir, $options);

            $totalWritten += $result->getFileCount();
            $totalSkipped += $result->getSkippedCount();

            if ($result->hasErrors()) {
                $errors = [...$errors, ...$result->errors];
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        if ($options->dryRun) {
            $io->success(sprintf('Would write %d file(s).', $totalWritten));
        } else {
            $io->success(sprintf(
                'Exported %d file(s), skipped %d.',
                $totalWritten,
                $totalSkipped
            ));
        }

        if ($errors !== []) {
            $io->warning(sprintf('%d error(s) occurred:', count($errors)));
            foreach ($errors as $error) {
                $io->writeln('  - ' . $error);
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function handleResult(
        \App\ValueObject\ExportResult $result,
        ExportOptions $options,
        SymfonyStyle $io,
    ): int {
        if ($result->hasErrors()) {
            foreach ($result->errors as $error) {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if ($options->dryRun) {
            $io->success(sprintf(
                'Would write %d file(s) (%d bytes).',
                $result->getFileCount(),
                $result->bytesWritten
            ));

            if ($result->filesWritten !== []) {
                $io->listing($result->filesWritten);
            }

            return Command::SUCCESS;
        }

        if ($result->getFileCount() === 0 && $result->getSkippedCount() > 0) {
            $io->info(sprintf('Skipped %d file(s) (already exist, use --force to overwrite).', $result->getSkippedCount()));
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Exported %d file(s) (%d bytes).',
            $result->getFileCount(),
            $result->bytesWritten
        ));

        if ($result->filesWritten !== []) {
            $io->listing($result->filesWritten);
        }

        if ($result->backupsCreated !== []) {
            $io->note(sprintf('Created %d backup file(s).', count($result->backupsCreated)));
        }

        return Command::SUCCESS;
    }

    private function resolveTokenId(string $tokenFilter): ?Uuid
    {
        if (Uuid::isValid($tokenFilter)) {
            $token = $this->tokenRepository->find(Uuid::fromString($tokenFilter));
            return $token?->getId();
        }

        $token = $this->tokenRepository->findOneBy(['name' => $tokenFilter]);
        return $token?->getId();
    }

    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) ?? $name;
    }
}
