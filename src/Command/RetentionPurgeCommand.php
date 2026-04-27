<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DriftPayloadRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sentinel:retention:purge',
    description: 'Purge old drift payload records based on retention policy',
)]
final class RetentionPurgeCommand extends Command
{
    public function __construct(
        private readonly DriftPayloadRepositoryInterface $driftPayloadRepository,
        private readonly int $defaultRetentionDays,
        private readonly int $defaultBatchSize,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Number of days to retain (overrides SENTINEL_RETENTION_DAYS)',
                null
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_REQUIRED,
                'Number of records to delete per batch (overrides SENTINEL_RETENTION_BATCH_SIZE)',
                null
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show count of records that would be deleted without actually deleting'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $daysOption = $input->getOption('days');
        $days = is_numeric($daysOption) ? (int) $daysOption : $this->defaultRetentionDays;

        $batchSizeOption = $input->getOption('batch-size');
        $batchSize = is_numeric($batchSizeOption) ? (int) $batchSizeOption : $this->defaultBatchSize;

        $dryRun = (bool) $input->getOption('dry-run');

        if ($days <= 0) {
            $io->warning('Retention policy is disabled (days <= 0). No records will be purged.');
            return Command::SUCCESS;
        }

        if ($batchSize <= 0) {
            $io->error('Batch size must be greater than 0.');
            return Command::FAILURE;
        }

        $cutoff = new \DateTimeImmutable("-{$days} days");

        $io->title('Sentinel Retention Purge');
        $io->text([
            sprintf('Retention period: <info>%d days</info>', $days),
            sprintf('Cutoff date: <info>%s</info>', $cutoff->format('Y-m-d H:i:s')),
            sprintf('Batch size: <info>%d</info>', $batchSize),
        ]);

        if ($dryRun) {
            $count = $this->driftPayloadRepository->countOlderThan($cutoff);
            $io->note(sprintf('DRY RUN: Would delete %d drift payload record(s).', $count));
            return Command::SUCCESS;
        }

        $io->text('Purging old drift payload records...');

        $startTime = microtime(true);
        $deleted = $this->driftPayloadRepository->deleteOlderThan($cutoff, $batchSize);
        $elapsed = microtime(true) - $startTime;

        $io->success(sprintf(
            'Purged %d drift payload record(s) in %.2f seconds.',
            $deleted,
            $elapsed
        ));

        return Command::SUCCESS;
    }
}
