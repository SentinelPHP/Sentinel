<?php

declare(strict_types=1);

namespace App\Command;

use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use App\Repository\ApiTokenRepository;
use App\Repository\SchemaDriftRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'sentinel:drift:list',
    description: 'List recent schema drifts with filters and severity distribution',
)]
final class ListDriftCommand extends Command
{
    public function __construct(
        private readonly SchemaDriftRepository $driftRepository,
        private readonly ApiTokenRepository $tokenRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Filter by token name or UUID')
            ->addOption('severity', 's', InputOption::VALUE_REQUIRED, 'Filter by severity (info|warning|critical)')
            ->addOption('drift-type', null, InputOption::VALUE_REQUIRED, 'Filter by drift type (field_added|field_removed|type_changed|structure_changed)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Filter from date (Y-m-d or Y-m-d H:i:s)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Filter to date (Y-m-d or Y-m-d H:i:s)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of results', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filters = $this->buildFilters($input, $io);
        if ($filters === null) {
            return Command::FAILURE;
        }

        /** @var string $limitInput */
        $limitInput = $input->getOption('limit');
        $limit = (int) $limitInput;

        $drifts = $this->driftRepository->findWithFilters($filters, $limit);
        $total = $this->driftRepository->countWithFilters($filters);
        $severityCounts = $this->driftRepository->countBySeverityWithFilters($filters);

        $this->displaySeverityDistribution($io, $severityCounts);

        if (count($drifts) === 0) {
            $io->info('No drifts found matching the specified filters.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($drifts as $drift) {
            $schema = $drift->getSchema();
            $rows[] = [
                substr($drift->getId()->toRfc4122(), 0, 8) . '...',
                $this->formatSeverity($drift->getSeverity()),
                $drift->getDriftType()->value,
                $this->truncate($drift->getPath(), 30),
                $this->truncate($schema->getTargetHost() . $schema->getEndpointPath(), 35),
                $drift->getToken()->getName(),
                $drift->getCreatedAt()->format('Y-m-d H:i'),
            ];
        }

        $io->table(
            ['ID', 'Severity', 'Type', 'Path', 'Endpoint', 'Token', 'Created'],
            $rows
        );

        $showing = count($drifts);
        if ($total > $showing) {
            $io->note(sprintf('Showing %d of %d total drifts. Use --limit to see more.', $showing, $total));
        } else {
            $io->writeln(sprintf('<info>Total: %d drift(s)</info>', $total));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, int> $counts
     */
    private function displaySeverityDistribution(SymfonyStyle $io, array $counts): void
    {
        $total = array_sum($counts);
        if ($total === 0) {
            return;
        }

        $critical = $counts[DriftSeverity::Critical->value] ?? 0;
        $warning = $counts[DriftSeverity::Warning->value] ?? 0;
        $info = $counts[DriftSeverity::Info->value] ?? 0;

        $io->section('Severity Distribution');
        $io->writeln(sprintf(
            '  <fg=red>Critical: %d</> | <fg=yellow>Warning: %d</> | <fg=blue>Info: %d</> | Total: %d',
            $critical,
            $warning,
            $info,
            $total
        ));
        $io->newLine();
    }

    private function formatSeverity(DriftSeverity $severity): string
    {
        return match ($severity) {
            DriftSeverity::Critical => '<fg=red>critical</>',
            DriftSeverity::Warning => '<fg=yellow>warning</>',
            DriftSeverity::Info => '<fg=blue>info</>',
        };
    }

    /**
     * @return array{tokenId?: Uuid, severity?: DriftSeverity, driftType?: DriftType, from?: \DateTimeImmutable, to?: \DateTimeImmutable}|null
     */
    private function buildFilters(InputInterface $input, SymfonyStyle $io): ?array
    {
        $filters = [];

        /** @var string|null $tokenFilter */
        $tokenFilter = $input->getOption('token');
        if ($tokenFilter !== null) {
            $tokenId = $this->resolveTokenId($tokenFilter);
            if ($tokenId === null) {
                $io->error(sprintf('Token not found: %s', $tokenFilter));
                return null;
            }
            $filters['tokenId'] = $tokenId;
        }

        /** @var string|null $severityFilter */
        $severityFilter = $input->getOption('severity');
        if ($severityFilter !== null) {
            $severity = DriftSeverity::tryFrom($severityFilter);
            if ($severity === null) {
                $io->error(sprintf('Invalid severity: %s. Valid values: %s', $severityFilter, implode(', ', DriftSeverity::values())));
                return null;
            }
            $filters['severity'] = $severity;
        }

        /** @var string|null $driftTypeFilter */
        $driftTypeFilter = $input->getOption('drift-type');
        if ($driftTypeFilter !== null) {
            $driftType = DriftType::tryFrom($driftTypeFilter);
            if ($driftType === null) {
                $io->error(sprintf('Invalid drift type: %s. Valid values: %s', $driftTypeFilter, implode(', ', DriftType::values())));
                return null;
            }
            $filters['driftType'] = $driftType;
        }

        /** @var string|null $fromFilter */
        $fromFilter = $input->getOption('from');
        if ($fromFilter !== null) {
            $from = $this->parseDate($fromFilter);
            if ($from === null) {
                $io->error(sprintf('Invalid from date: %s. Use format Y-m-d or Y-m-d H:i:s', $fromFilter));
                return null;
            }
            $filters['from'] = $from;
        }

        /** @var string|null $toFilter */
        $toFilter = $input->getOption('to');
        if ($toFilter !== null) {
            $to = $this->parseDate($toFilter);
            if ($to === null) {
                $io->error(sprintf('Invalid to date: %s. Use format Y-m-d or Y-m-d H:i:s', $toFilter));
                return null;
            }
            $filters['to'] = $to;
        }

        return $filters;
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

    private function parseDate(string $dateString): ?\DateTimeImmutable
    {
        $formats = ['Y-m-d H:i:s', 'Y-m-d'];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $dateString);
            if ($date !== false) {
                if ($format === 'Y-m-d') {
                    $date = $date->setTime(0, 0, 0);
                }
                return $date;
            }
        }
        return null;
    }

    private function truncate(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength - 3) . '...';
    }
}
