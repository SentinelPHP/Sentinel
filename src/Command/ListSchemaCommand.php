<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ApiSchemaRepository;
use App\Repository\ApiTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'sentinel:schema:list',
    description: 'List all schemas with optional filters',
)]
final class ListSchemaCommand extends Command
{
    public function __construct(
        private readonly ApiSchemaRepository $schemaRepository,
        private readonly ApiTokenRepository $tokenRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Filter by token name or UUID')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Filter by target host (partial match)')
            ->addOption('endpoint', 'p', InputOption::VALUE_REQUIRED, 'Filter by endpoint path (partial match)')
            ->addOption('master-only', 'm', InputOption::VALUE_NONE, 'Show only master schemas')
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

        $schemas = $this->schemaRepository->findWithFilters($filters, $limit);
        $total = $this->schemaRepository->countWithFilters($filters);

        if (count($schemas) === 0) {
            $io->info('No schemas found matching the specified filters.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($schemas as $schema) {
            $rows[] = [
                substr($schema->getId()->toRfc4122(), 0, 8) . '...',
                $schema->getHttpMethod(),
                $this->truncate($schema->getTargetHost() . $schema->getEndpointPath(), 40),
                $schema->getSchemaType()->value,
                (string) $schema->getVersion(),
                $schema->isMaster() ? '✓' : '',
                (string) $schema->getSampleCount(),
                $schema->getToken()->getName(),
                $schema->getUpdatedAt()->format('Y-m-d H:i'),
            ];
        }

        $io->table(
            ['ID', 'Method', 'Endpoint', 'Type', 'Ver', 'Master', 'Samples', 'Token', 'Updated'],
            $rows
        );

        $showing = count($schemas);
        if ($total > $showing) {
            $io->note(sprintf('Showing %d of %d total schemas. Use --limit to see more.', $showing, $total));
        } else {
            $io->writeln(sprintf('<info>Total: %d schema(s)</info>', $total));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{tokenId?: Uuid, targetHost?: string, endpointPath?: string, masterOnly?: bool}|null
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

        /** @var string|null $hostFilter */
        $hostFilter = $input->getOption('host');
        if ($hostFilter !== null) {
            $filters['targetHost'] = $hostFilter;
        }

        /** @var string|null $endpointFilter */
        $endpointFilter = $input->getOption('endpoint');
        if ($endpointFilter !== null) {
            $filters['endpointPath'] = $endpointFilter;
        }

        if ($input->getOption('master-only')) {
            $filters['masterOnly'] = true;
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
