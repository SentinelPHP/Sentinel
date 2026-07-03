<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ApiTokenRepository;
use App\Repository\GeneratedDtoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'sentinel:dto:list',
    description: 'List all generated DTOs with optional filters',
)]
final class ListDtoCommand extends Command
{
    public function __construct(
        private readonly GeneratedDtoRepository $dtoRepository,
        private readonly ApiTokenRepository $tokenRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Filter by token name or UUID')
            ->addOption('endpoint', 'p', InputOption::VALUE_REQUIRED, 'Filter by endpoint path (partial match)')
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Filter by class name (partial match)')
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

        $dtos = $this->dtoRepository->findWithFilters($filters, $limit);
        $total = $this->dtoRepository->countWithFilters($filters);

        if ($dtos === []) {
            $io->info('No generated DTOs found matching the specified filters.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($dtos as $dto) {
            $schema = $dto->getSchema();
            $versionCount = count($this->dtoRepository->findAllVersions($schema));

            $rows[] = [
                substr($dto->getId()->toRfc4122(), 0, 8) . '...',
                $dto->getClassName(),
                $this->truncate($dto->getNamespace(), 30),
                $this->truncate($schema->getHttpMethod() . ' ' . $schema->getEndpointPath(), 35),
                (string) $dto->getVersion(),
                (string) $versionCount,
                $dto->isCurrent() ? '✓' : '',
                $dto->getCreatedAt()->format('Y-m-d H:i'),
            ];
        }

        $io->table(
            ['ID', 'Class', 'Namespace', 'Endpoint', 'Ver', 'Versions', 'Current', 'Created'],
            $rows
        );

        $showing = count($dtos);
        if ($total > $showing) {
            $io->note(sprintf('Showing %d of %d total DTOs. Use --limit to see more.', $showing, $total));
        } else {
            $io->writeln(sprintf('<info>Total: %d DTO(s)</info>', $total));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{tokenId?: Uuid, className?: string, endpointPath?: string}|null
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

        /** @var string|null $endpointFilter */
        $endpointFilter = $input->getOption('endpoint');
        if ($endpointFilter !== null) {
            $filters['endpointPath'] = $endpointFilter;
        }

        /** @var string|null $classFilter */
        $classFilter = $input->getOption('class');
        if ($classFilter !== null) {
            $filters['className'] = $classFilter;
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

    private function truncate(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength - 3) . '...';
    }
}
