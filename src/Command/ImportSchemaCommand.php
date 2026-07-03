<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ApiSchema;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Repository\ApiSchemaRepository;
use App\Repository\ApiSchemaRepositoryInterface;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'sentinel:schema:import',
    description: 'Import a JSON Schema from file and associate with token/endpoint',
)]
final class ImportSchemaCommand extends Command
{
    public function __construct(
        private readonly ApiSchemaRepository $schemaRepository,
        private readonly ApiSchemaRepositoryInterface $cachedSchemaRepository,
        private readonly ApiTokenRepository $tokenRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the JSON Schema file')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Token name or UUID')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Target host (e.g., https://api.example.com)')
            ->addOption('endpoint', 'p', InputOption::VALUE_REQUIRED, 'Endpoint path (e.g., /users/{id})')
            ->addOption('method', 'm', InputOption::VALUE_REQUIRED, 'HTTP method (GET, POST, etc.)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Schema type: request or response')
            ->addOption('master', null, InputOption::VALUE_NONE, 'Immediately promote as master schema')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate without persisting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Validate required options
        $missingOptions = $this->validateRequiredOptions($input);
        if (count($missingOptions) > 0) {
            $io->error(sprintf('Missing required options: %s', implode(', ', $missingOptions)));
            return Command::FAILURE;
        }

        /** @var string $filePath */
        $filePath = $input->getArgument('file');

        // Read and parse file
        $jsonSchema = $this->readSchemaFile($filePath, $io);
        if ($jsonSchema === null) {
            return Command::FAILURE;
        }

        // Resolve token
        /** @var string $tokenInput */
        $tokenInput = $input->getOption('token');
        $token = $this->resolveToken($tokenInput);
        if ($token === null) {
            $io->error(sprintf('Token not found: %s', $tokenInput));
            return Command::FAILURE;
        }

        // Parse schema type
        /** @var string $typeInput */
        $typeInput = $input->getOption('type');
        $schemaType = SchemaType::tryFrom($typeInput);
        if ($schemaType === null) {
            $io->error(sprintf(
                'Invalid schema type: %s. Valid values: %s',
                $typeInput,
                implode(', ', SchemaType::values())
            ));
            return Command::FAILURE;
        }

        /** @var string $host */
        $host = $input->getOption('host');
        /** @var string $endpoint */
        $endpoint = $input->getOption('endpoint');
        /** @var string $methodInput */
        $methodInput = $input->getOption('method');
        $method = strtoupper($methodInput);

        $setAsMaster = (bool) $input->getOption('master');
        $dryRun = (bool) $input->getOption('dry-run');

        // Determine next version
        $existingVersions = $this->schemaRepository->findAllVersions(
            $token->getId(),
            $host,
            $endpoint,
            $method,
            $schemaType,
        );
        $nextVersion = count($existingVersions) > 0
            ? max(array_map(static fn (ApiSchema $s) => $s->getVersion(), $existingVersions)) + 1
            : 1;

        // Create schema entity
        $schema = new ApiSchema();
        $schema->setToken($token)
            ->setTargetHost($host)
            ->setEndpointPath($endpoint)
            ->setHttpMethod((string) $method)
            ->setSchemaType($schemaType)
            ->setJsonSchema($jsonSchema)
            ->setVersion($nextVersion)
            ->setSampleCount(0);

        if ($dryRun) {
            $io->success('Dry run: Schema is valid and would be imported.');
            $this->printSchemaInfo($schema, $setAsMaster, $io);
            return Command::SUCCESS;
        }

        // Handle master promotion
        if ($setAsMaster) {
            // Demote ALL existing masters (handles data integrity issues)
            $existingMasters = $this->schemaRepository->findMasterSchemas(
                $token->getId(),
                $host,
                $endpoint,
                $method,
                $schemaType,
            );

            foreach ($existingMasters as $existingMaster) {
                $existingMaster->setIsMaster(false);
            }

            $schema->setIsMaster(true);
        }

        $this->entityManager->persist($schema);
        $this->entityManager->flush();

        // Invalidate cache if set as master
        if ($setAsMaster) {
            $this->cachedSchemaRepository->invalidateMasterSchema(
                $token->getId(),
                $host,
                $endpoint,
                $method,
                $schemaType,
            );
        }

        $io->success('Schema imported successfully!');
        $this->printSchemaInfo($schema, $setAsMaster, $io);

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function validateRequiredOptions(InputInterface $input): array
    {
        $required = ['token', 'host', 'endpoint', 'method', 'type'];
        $missing = [];

        foreach ($required as $option) {
            if ($input->getOption($option) === null) {
                $missing[] = '--' . $option;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSchemaFile(string $filePath, SymfonyStyle $io): ?array
    {
        if (!file_exists($filePath)) {
            $io->error(sprintf('File not found: %s', $filePath));
            return null;
        }

        if (!is_readable($filePath)) {
            $io->error(sprintf('File is not readable: %s', $filePath));
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            $io->error(sprintf('Failed to read file: %s', $filePath));
            return null;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $io->error(sprintf('Invalid JSON in file: %s', $filePath));
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function resolveToken(string $tokenInput): ?\App\Entity\ApiToken
    {
        if (Uuid::isValid($tokenInput)) {
            return $this->tokenRepository->find(Uuid::fromString($tokenInput));
        }

        return $this->tokenRepository->findOneBy(['name' => $tokenInput]);
    }

    private function printSchemaInfo(ApiSchema $schema, bool $setAsMaster, SymfonyStyle $io): void
    {
        $io->table(
            ['Property', 'Value'],
            [
                ['Schema ID', $schema->getId()->toRfc4122()],
                ['Token', $schema->getToken()->getName()],
                ['Endpoint', sprintf('%s %s%s', $schema->getHttpMethod(), $schema->getTargetHost(), $schema->getEndpointPath())],
                ['Schema Type', $schema->getSchemaType()->value],
                ['Version', (string) $schema->getVersion()],
                ['Master', $setAsMaster ? 'Yes' : 'No'],
            ]
        );
    }
}
