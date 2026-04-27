<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ApiSchemaRepository;
use App\Repository\ApiTokenRepository;
use App\Service\Dto\DtoGeneratorServiceInterface;
use App\Service\Dto\DtoStorageServiceInterface;
use App\ValueObject\GeneratedDto;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'sentinel:dto:generate',
    description: 'Generate DTOs from API schemas',
)]
final class GenerateDtoCommand extends Command
{
    public function __construct(
        private readonly DtoGeneratorServiceInterface $dtoGenerator,
        private readonly DtoStorageServiceInterface $dtoStorage,
        private readonly ApiSchemaRepository $schemaRepository,
        private readonly ApiTokenRepository $tokenRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('schema-id', 's', InputOption::VALUE_REQUIRED, 'Generate DTO for specific schema UUID')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Filter by token name or UUID')
            ->addOption('endpoint', 'p', InputOption::VALUE_REQUIRED, 'Filter by endpoint path')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Generate DTOs for all master schemas')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without saving to database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $schemaId */
        $schemaId = $input->getOption('schema-id');
        /** @var string|null $tokenFilter */
        $tokenFilter = $input->getOption('token');
        /** @var string|null $endpointFilter */
        $endpointFilter = $input->getOption('endpoint');
        $generateAll = (bool) $input->getOption('all');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($schemaId !== null) {
            return $this->generateForSchema($schemaId, $dryRun, $io, $output);
        }

        if ($generateAll || $tokenFilter !== null || $endpointFilter !== null) {
            return $this->generateForFilters($tokenFilter, $endpointFilter, $dryRun, $io);
        }

        $io->error('Please specify --schema-id, --token, --endpoint, or --all.');
        return Command::FAILURE;
    }

    private function generateForSchema(string $schemaIdInput, bool $dryRun, SymfonyStyle $io, OutputInterface $output): int
    {
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

        $io->info(sprintf(
            'Generating DTO for %s %s...',
            $schema->getHttpMethod(),
            $schema->getEndpointPath()
        ));

        $generatedDto = $this->dtoGenerator->generateFromSchema($schema);

        if ($dryRun) {
            $io->note('Dry run mode - not saving to database.');
            $io->section('Generated PHP Code');
            $output->writeln($generatedDto->phpCode);

            $this->printNestedDtos($generatedDto, $output);

            return Command::SUCCESS;
        }

        $stored = $this->dtoStorage->storeAll($generatedDto);

        if ($stored === []) {
            $io->info('DTO is unchanged from current version.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Generated and stored %d DTO(s).', count($stored)));
        $this->printStoredDtos($stored, $io);

        return Command::SUCCESS;
    }

    private function generateForFilters(?string $tokenFilter, ?string $endpointFilter, bool $dryRun, SymfonyStyle $io): int
    {
        $filters = ['masterOnly' => true];

        if ($tokenFilter !== null) {
            $tokenId = $this->resolveTokenId($tokenFilter);
            if ($tokenId === null) {
                $io->error(sprintf('Token not found: %s', $tokenFilter));
                return Command::FAILURE;
            }
            $filters['tokenId'] = $tokenId;
        }

        if ($endpointFilter !== null) {
            $filters['endpointPath'] = $endpointFilter;
        }

        $schemas = $this->schemaRepository->findWithFilters($filters, limit: 1000);

        if ($schemas === []) {
            $io->info('No master schemas found matching the specified filters.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d master schema(s) to process.', count($schemas)));

        if ($dryRun) {
            $io->note('Dry run mode - not saving to database.');
        }

        $totalGenerated = 0;
        $totalUnchanged = 0;
        $errors = [];

        $io->progressStart(count($schemas));

        foreach ($schemas as $schema) {
            try {
                $generatedDto = $this->dtoGenerator->generateFromSchema($schema);

                if (!$dryRun) {
                    $stored = $this->dtoStorage->storeAll($generatedDto);
                    if ($stored === []) {
                        $totalUnchanged++;
                    } else {
                        $totalGenerated += count($stored);
                    }
                } else {
                    $totalGenerated++;
                }
            } catch (\Throwable $e) {
                $errors[] = sprintf(
                    '%s %s: %s',
                    $schema->getHttpMethod(),
                    $schema->getEndpointPath(),
                    $e->getMessage()
                );
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        if ($dryRun) {
            $io->success(sprintf('Would generate DTOs for %d schema(s).', $totalGenerated));
        } else {
            $io->success(sprintf(
                'Generated %d new DTO(s), %d unchanged.',
                $totalGenerated,
                $totalUnchanged
            ));
        }

        if ($errors !== []) {
            $io->warning(sprintf('%d error(s) occurred:', count($errors)));
            foreach ($errors as $error) {
                $io->writeln('  - ' . $error);
            }
        }

        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
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

    private function printNestedDtos(GeneratedDto $dto, OutputInterface $output): void
    {
        $nestedDtos = $dto->nestedDtos;
        if ($nestedDtos === []) {
            return;
        }

        $output->writeln('');
        $output->writeln(sprintf('<comment>Nested DTOs (%d):</comment>', count($nestedDtos)));

        foreach ($nestedDtos as $nested) {
            $output->writeln('');
            $output->writeln(sprintf('<info>--- %s ---</info>', $nested->className));
            $output->writeln($nested->phpCode);
        }
    }

    /**
     * @param list<\App\Entity\GeneratedDto> $stored
     */
    private function printStoredDtos(array $stored, SymfonyStyle $io): void
    {
        $rows = [];
        foreach ($stored as $dto) {
            $rows[] = [
                $dto->getClassName(),
                $dto->getNamespace(),
                'v' . $dto->getVersion(),
            ];
        }

        $io->table(['Class', 'Namespace', 'Version'], $rows);
    }
}
