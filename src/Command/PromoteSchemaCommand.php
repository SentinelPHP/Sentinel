<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ApiSchema;
use App\Enum\TokenMode;
use App\Event\SchemaPromotedEvent;
use App\Repository\ApiSchemaRepository;
use App\Repository\ApiSchemaRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand(
    name: 'sentinel:schema:promote',
    description: 'Promote a learned schema to master status',
)]
final class PromoteSchemaCommand extends Command
{
    public function __construct(
        private readonly ApiSchemaRepository $schemaRepository,
        private readonly ApiSchemaRepositoryInterface $cachedSchemaRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('schema-id', InputArgument::REQUIRED, 'UUID of the schema to promote')
            ->addOption(
                'switch-mode',
                's',
                InputOption::VALUE_NONE,
                'Switch the token mode from learning to validating'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $schemaIdInput */
        $schemaIdInput = $input->getArgument('schema-id');

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

        if ($schema->isMaster()) {
            $io->warning('Schema is already the master schema.');
            return Command::SUCCESS;
        }

        $previousMaster = $this->promoteSchema($schema);

        $switchMode = $input->getOption('switch-mode');
        $token = $schema->getToken();

        if ($switchMode) {
            $currentMode = $token->getMode();

            if ($currentMode === TokenMode::Learning) {
                $token->setMode(TokenMode::Validating);
                $io->note(sprintf(
                    'Token "%s" mode switched from %s to %s',
                    $token->getName(),
                    TokenMode::Learning->value,
                    TokenMode::Validating->value
                ));
            } elseif ($currentMode === TokenMode::Validating) {
                $io->warning('Token is already in validating mode.');
            } else {
                $io->warning(sprintf(
                    'Token is in %s mode. Mode switch only applies to learning mode.',
                    $currentMode->value
                ));
            }
        }

        $this->entityManager->flush();

        // Dispatch event after successful promotion
        $this->eventDispatcher->dispatch(new SchemaPromotedEvent($schema, $previousMaster));

        $io->success('Schema promoted to master successfully!');
        $io->table(
            ['Property', 'Value'],
            [
                ['Schema ID', $schema->getId()->toRfc4122()],
                ['Endpoint', sprintf('%s %s%s', $schema->getHttpMethod(), $schema->getTargetHost(), $schema->getEndpointPath())],
                ['Schema Type', $schema->getSchemaType()->value],
                ['Version', (string) $schema->getVersion()],
                ['Sample Count', (string) $schema->getSampleCount()],
                ['Token', $token->getName()],
                ['Token Mode', $token->getMode()->value],
            ]
        );

        return Command::SUCCESS;
    }

    private function promoteSchema(ApiSchema $schema): ?ApiSchema
    {
        // Demote ALL existing masters (handles data integrity issues where multiple masters exist)
        $existingMasters = $this->schemaRepository->findMasterSchemas(
            $schema->getToken()->getId(),
            $schema->getTargetHost(),
            $schema->getEndpointPath(),
            $schema->getHttpMethod(),
            $schema->getSchemaType(),
        );

        $previousMaster = $existingMasters[0] ?? null;

        foreach ($existingMasters as $existingMaster) {
            $existingMaster->setIsMaster(false);
        }

        $schema->setIsMaster(true);

        $this->cachedSchemaRepository->invalidateMasterSchema(
            $schema->getToken()->getId(),
            $schema->getTargetHost(),
            $schema->getEndpointPath(),
            $schema->getHttpMethod(),
            $schema->getSchemaType(),
        );

        return $previousMaster;
    }
}
