<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GeneratedDto;
use App\Repository\GeneratedDtoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'sentinel:dto:diff',
    description: 'Show diff between DTO versions',
)]
final class DiffDtoCommand extends Command
{
    public function __construct(
        private readonly GeneratedDtoRepository $dtoRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dto-id', InputArgument::REQUIRED, 'UUID of the DTO')
            ->addOption('dto-version', null, InputOption::VALUE_REQUIRED, 'Compare with specific version (or "previous")')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Compare from version')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Compare to version')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Output raw diff only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $dtoIdInput */
        $dtoIdInput = $input->getArgument('dto-id');

        if (!Uuid::isValid($dtoIdInput)) {
            $io->error(sprintf('Invalid UUID format: %s', $dtoIdInput));
            return Command::FAILURE;
        }

        $dtoId = Uuid::fromString($dtoIdInput);
        $dto = $this->dtoRepository->find($dtoId);

        if ($dto === null) {
            $io->error(sprintf('DTO not found: %s', $dtoIdInput));
            return Command::FAILURE;
        }

        $schema = $dto->getSchema();
        $allVersions = $this->dtoRepository->findAllVersions($schema);

        if (count($allVersions) < 2) {
            $io->warning('Only one version exists for this DTO. Nothing to compare.');
            return Command::SUCCESS;
        }

        $isRaw = (bool) $input->getOption('raw');

        /** @var string|null $fromOption */
        $fromOption = $input->getOption('from');
        /** @var string|null $toOption */
        $toOption = $input->getOption('to');
        /** @var string|null $versionOption */
        $versionOption = $input->getOption('dto-version');

        if ($fromOption !== null && $toOption !== null) {
            return $this->compareVersions($allVersions, (int) $fromOption, (int) $toOption, $io, $output, $isRaw);
        }

        if ($versionOption !== null) {
            return $this->compareWithVersion($dto, $allVersions, $versionOption, $io, $output, $isRaw);
        }

        // Default: compare current with previous
        return $this->compareWithVersion($dto, $allVersions, 'previous', $io, $output, $isRaw);
    }

    /**
     * @param list<GeneratedDto> $allVersions
     */
    private function compareVersions(
        array $allVersions,
        int $fromVersion,
        int $toVersion,
        SymfonyStyle $io,
        OutputInterface $output,
        bool $raw,
    ): int {
        $fromDto = $this->findVersion($allVersions, $fromVersion);
        $toDto = $this->findVersion($allVersions, $toVersion);

        if ($fromDto === null) {
            $io->error(sprintf('Version %d not found.', $fromVersion));
            return Command::FAILURE;
        }

        if ($toDto === null) {
            $io->error(sprintf('Version %d not found.', $toVersion));
            return Command::FAILURE;
        }

        return $this->showDiff($fromDto, $toDto, $io, $output, $raw);
    }

    /**
     * @param list<GeneratedDto> $allVersions
     */
    private function compareWithVersion(
        GeneratedDto $currentDto,
        array $allVersions,
        string $versionOption,
        SymfonyStyle $io,
        OutputInterface $output,
        bool $raw,
    ): int {
        $compareDto = $this->resolveCompareVersion($currentDto, $allVersions, $versionOption);

        if ($compareDto === null) {
            $io->error(sprintf('Could not find version to compare: %s', $versionOption));
            return Command::FAILURE;
        }

        return $this->showDiff($compareDto, $currentDto, $io, $output, $raw);
    }

    /**
     * @param list<GeneratedDto> $allVersions
     */
    private function resolveCompareVersion(GeneratedDto $currentDto, array $allVersions, string $versionOption): ?GeneratedDto
    {
        if ($versionOption === 'previous') {
            $currentVersion = $currentDto->getVersion();
            return $this->findVersion($allVersions, $currentVersion - 1);
        }

        $targetVersion = (int) $versionOption;
        if ($targetVersion <= 0) {
            return null;
        }

        return $this->findVersion($allVersions, $targetVersion);
    }

    /**
     * @param list<GeneratedDto> $allVersions
     */
    private function findVersion(array $allVersions, int $version): ?GeneratedDto
    {
        foreach ($allVersions as $dto) {
            if ($dto->getVersion() === $version) {
                return $dto;
            }
        }
        return null;
    }

    private function showDiff(
        GeneratedDto $oldDto,
        GeneratedDto $newDto,
        SymfonyStyle $io,
        OutputInterface $output,
        bool $raw,
    ): int {
        $oldCode = $oldDto->getPhpCode();
        $newCode = $newDto->getPhpCode();

        if ($oldCode === $newCode) {
            if (!$raw) {
                $io->success('No differences found between versions.');
            }
            return Command::SUCCESS;
        }

        if (!$raw) {
            $io->title(sprintf(
                'DTO Diff: v%d → v%d',
                $oldDto->getVersion(),
                $newDto->getVersion()
            ));
            $io->definitionList(
                ['Class' => $newDto->getFullyQualifiedClassName()],
                ['From Version' => (string) $oldDto->getVersion()],
                ['To Version' => (string) $newDto->getVersion()],
            );
            $io->section('Differences');
        }

        $this->printUnifiedDiff($oldCode, $newCode, $output);

        return Command::SUCCESS;
    }

    private function printUnifiedDiff(string $oldCode, string $newCode, OutputInterface $output): void
    {
        $oldLines = explode("\n", $oldCode);
        $newLines = explode("\n", $newCode);

        $diff = $this->computeLineDiff($oldLines, $newLines);

        foreach ($diff as $line) {
            $output->writeln($line);
        }
    }

    /**
     * Compute a simple line-by-line diff.
     *
     * @param list<string> $oldLines
     * @param list<string> $newLines
     * @return list<string>
     */
    private function computeLineDiff(array $oldLines, array $newLines): array
    {
        $result = [];
        $maxLines = max(count($oldLines), count($newLines));

        $oldIndex = 0;
        $newIndex = 0;

        while ($oldIndex < count($oldLines) || $newIndex < count($newLines)) {
            $oldLine = $oldLines[$oldIndex] ?? null;
            $newLine = $newLines[$newIndex] ?? null;

            if ($oldLine === $newLine) {
                $result[] = '  ' . ($oldLine ?? '');
                $oldIndex++;
                $newIndex++;
            } elseif ($oldLine !== null && !in_array($oldLine, array_slice($newLines, $newIndex), true)) {
                $result[] = sprintf('<fg=red>- %s</>', $oldLine);
                $oldIndex++;
            } elseif ($newLine !== null && !in_array($newLine, array_slice($oldLines, $oldIndex), true)) {
                $result[] = sprintf('<fg=green>+ %s</>', $newLine);
                $newIndex++;
            } else {
                if ($oldLine !== null) {
                    $result[] = sprintf('<fg=red>- %s</>', $oldLine);
                    $oldIndex++;
                }
                if ($newLine !== null) {
                    $result[] = sprintf('<fg=green>+ %s</>', $newLine);
                    $newIndex++;
                }
            }
        }

        return $result;
    }
}
