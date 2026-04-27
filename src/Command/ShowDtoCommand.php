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
    name: 'sentinel:dto:show',
    description: 'Display the generated PHP code for a DTO',
)]
final class ShowDtoCommand extends Command
{
    public function __construct(
        private readonly GeneratedDtoRepository $dtoRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dto-id', InputArgument::REQUIRED, 'UUID of the DTO to display')
            ->addOption('dto-version', null, InputOption::VALUE_REQUIRED, 'Show specific version')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Output raw PHP code only (for piping)');
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

        /** @var string|null $versionOption */
        $versionOption = $input->getOption('dto-version');
        $isRaw = (bool) $input->getOption('raw');

        if ($versionOption !== null) {
            $targetVersion = (int) $versionOption;
            if ($targetVersion <= 0) {
                $io->error('Version must be a positive integer.');
                return Command::FAILURE;
            }

            $dto = $this->dtoRepository->findBySchemaAndVersion($dto->getSchema(), $targetVersion);
            if ($dto === null) {
                $io->error(sprintf('Version %d not found for this DTO.', $targetVersion));
                return Command::FAILURE;
            }
        }

        return $this->showDto($dto, $io, $output, $isRaw);
    }

    private function showDto(GeneratedDto $dto, SymfonyStyle $io, OutputInterface $output, bool $raw): int
    {
        $phpCode = $dto->getPhpCode();

        if ($raw) {
            $output->writeln($phpCode);
            return Command::SUCCESS;
        }

        $this->printDtoMetadata($dto, $io);
        $io->section('PHP Code');
        $output->writeln($phpCode);

        return Command::SUCCESS;
    }

    private function printDtoMetadata(GeneratedDto $dto, SymfonyStyle $io): void
    {
        $schema = $dto->getSchema();
        $allVersions = $this->dtoRepository->findAllVersions($schema);

        $io->definitionList(
            ['ID' => $dto->getId()->toRfc4122()],
            ['Class' => $dto->getFullyQualifiedClassName()],
            ['Version' => sprintf('%d of %d', $dto->getVersion(), count($allVersions))],
            ['Current' => $dto->isCurrent() ? 'Yes' : 'No'],
            ['Schema' => sprintf('%s %s', $schema->getHttpMethod(), $schema->getEndpointPath())],
            ['Schema ID' => $schema->getId()->toRfc4122()],
            ['Checksum' => substr($dto->getChecksum(), 0, 16) . '...'],
            ['Created' => $dto->getCreatedAt()->format('Y-m-d H:i:s')],
        );
    }
}
