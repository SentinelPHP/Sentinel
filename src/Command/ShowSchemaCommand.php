<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ApiSchema;
use App\Repository\ApiSchemaRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'sentinel:schema:show',
    description: 'Display a specific schema\'s JSON with optional version diff',
)]
final class ShowSchemaCommand extends Command
{
    public function __construct(
        private readonly ApiSchemaRepository $schemaRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('schema-id', InputArgument::REQUIRED, 'UUID of the schema to display')
            ->addOption('diff', 'd', InputOption::VALUE_REQUIRED, 'Compare with another version (number or "previous")')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Output raw JSON only (for piping)');
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

        /** @var string|null $diffOption */
        $diffOption = $input->getOption('diff');
        $isRaw = $input->getOption('raw');

        if ($diffOption !== null) {
            return $this->showDiff($schema, $diffOption, $io, $output, (bool) $isRaw);
        }

        return $this->showSchema($schema, $io, $output, (bool) $isRaw);
    }

    private function showSchema(ApiSchema $schema, SymfonyStyle $io, OutputInterface $output, bool $raw): int
    {
        $jsonSchema = $schema->getJsonSchema();
        $jsonOutput = json_encode($jsonSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($jsonOutput === false) {
            $io->error('Failed to encode schema as JSON');
            return Command::FAILURE;
        }

        if ($raw) {
            $output->writeln($jsonOutput);
            return Command::SUCCESS;
        }

        $this->printSchemaMetadata($schema, $io);
        $io->section('JSON Schema');
        $output->writeln($jsonOutput);

        return Command::SUCCESS;
    }

    private function showDiff(ApiSchema $schema, string $diffOption, SymfonyStyle $io, OutputInterface $output, bool $raw): int
    {
        $allVersions = $this->schemaRepository->findAllVersions(
            $schema->getToken()->getId(),
            $schema->getTargetHost(),
            $schema->getEndpointPath(),
            $schema->getHttpMethod(),
            $schema->getSchemaType(),
        );

        if (count($allVersions) < 2) {
            $io->warning('Only one version exists for this schema. Nothing to compare.');
            return $this->showSchema($schema, $io, $output, $raw);
        }

        $compareSchema = $this->resolveCompareSchema($schema, $allVersions, $diffOption);

        if ($compareSchema === null) {
            $io->error(sprintf('Could not find version to compare: %s', $diffOption));
            return Command::FAILURE;
        }

        if (!$raw) {
            $io->title(sprintf(
                'Schema Diff: v%d → v%d',
                $compareSchema->getVersion(),
                $schema->getVersion()
            ));
            $this->printSchemaMetadata($schema, $io);
        }

        $diff = $this->computeDiff($compareSchema->getJsonSchema(), $schema->getJsonSchema());

        if (empty($diff)) {
            $io->success('No differences found between versions.');
            return Command::SUCCESS;
        }

        if ($raw) {
            $output->writeln(json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        } else {
            $io->section('Differences');
            $this->printDiff($diff, $output);
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<ApiSchema> $allVersions
     */
    private function resolveCompareSchema(ApiSchema $currentSchema, array $allVersions, string $diffOption): ?ApiSchema
    {
        if ($diffOption === 'previous') {
            $currentVersion = $currentSchema->getVersion();
            foreach ($allVersions as $schema) {
                if ($schema->getVersion() === $currentVersion - 1) {
                    return $schema;
                }
            }
            return null;
        }

        $targetVersion = (int) $diffOption;
        if ($targetVersion <= 0) {
            return null;
        }

        foreach ($allVersions as $schema) {
            if ($schema->getVersion() === $targetVersion) {
                return $schema;
            }
        }

        return null;
    }

    private function printSchemaMetadata(ApiSchema $schema, SymfonyStyle $io): void
    {
        $io->definitionList(
            ['ID' => $schema->getId()->toRfc4122()],
            ['Token' => $schema->getToken()->getName()],
            ['Endpoint' => sprintf('%s %s%s', $schema->getHttpMethod(), $schema->getTargetHost(), $schema->getEndpointPath())],
            ['Type' => $schema->getSchemaType()->value],
            ['Version' => (string) $schema->getVersion()],
            ['Master' => $schema->isMaster() ? 'Yes' : 'No'],
            ['Samples' => (string) $schema->getSampleCount()],
            ['Updated' => $schema->getUpdatedAt()->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Compute differences between two schemas.
     *
     * @param array<mixed, mixed> $oldSchema
     * @param array<mixed, mixed> $newSchema
     * @return array<string, array{type: string, path: string, old?: mixed, new?: mixed}>
     */
    private function computeDiff(array $oldSchema, array $newSchema, string $path = ''): array
    {
        $diff = [];

        $allKeys = array_unique(array_merge(array_keys($oldSchema), array_keys($newSchema)));

        foreach ($allKeys as $key) {
            $keyStr = is_int($key) ? (string) $key : $key;
            $currentPath = $path === '' ? $keyStr : $path . '.' . $keyStr;
            $oldExists = array_key_exists($key, $oldSchema);
            $newExists = array_key_exists($key, $newSchema);

            if (!$oldExists && $newExists) {
                $diff[$currentPath] = [
                    'type' => 'added',
                    'path' => $currentPath,
                    'new' => $newSchema[$key],
                ];
            } elseif ($oldExists && !$newExists) {
                $diff[$currentPath] = [
                    'type' => 'removed',
                    'path' => $currentPath,
                    'old' => $oldSchema[$key],
                ];
            } elseif ($oldExists && $newExists) {
                $oldValue = $oldSchema[$key];
                $newValue = $newSchema[$key];

                if (is_array($oldValue) && is_array($newValue)) {
                    $nestedDiff = $this->computeDiff($oldValue, $newValue, $currentPath);
                    $diff = array_merge($diff, $nestedDiff);
                } elseif ($oldValue !== $newValue) {
                    $diff[$currentPath] = [
                        'type' => 'changed',
                        'path' => $currentPath,
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }
            }
        }

        return $diff;
    }

    /**
     * @param array<string, array{type: string, path: string, old?: mixed, new?: mixed}> $diff
     */
    private function printDiff(array $diff, OutputInterface $output): void
    {
        foreach ($diff as $change) {
            $path = $change['path'];
            $type = $change['type'];

            switch ($type) {
                case 'added':
                    $output->writeln(sprintf(
                        '<fg=green>+ %s</>: %s',
                        $path,
                        $this->formatValue($change['new'] ?? null)
                    ));
                    break;

                case 'removed':
                    $output->writeln(sprintf(
                        '<fg=red>- %s</>: %s',
                        $path,
                        $this->formatValue($change['old'] ?? null)
                    ));
                    break;

                case 'changed':
                    $output->writeln(sprintf(
                        '<fg=yellow>~ %s</>: %s → %s',
                        $path,
                        $this->formatValue($change['old'] ?? null),
                        $this->formatValue($change['new'] ?? null)
                    ));
                    break;
            }
        }
    }

    private function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES);
            return $json !== false ? $json : '(array)';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '(unknown)';
    }
}
