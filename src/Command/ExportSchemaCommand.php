<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ApiSchema;
use SentinelPHP\Dto\Enum\SchemaType;
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
    name: 'sentinel:schema:export',
    description: 'Export a schema to file in JSON Schema or OpenAPI format',
)]
final class ExportSchemaCommand extends Command
{
    private const FORMAT_JSON_SCHEMA = 'json-schema';
    private const FORMAT_OPENAPI = 'openapi';

    public function __construct(
        private readonly ApiSchemaRepository $schemaRepository,
        private readonly ApiTokenRepository $tokenRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Schema UUID to export')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Filter by token name or UUID')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Filter by target host')
            ->addOption('endpoint', 'p', InputOption::VALUE_REQUIRED, 'Filter by endpoint path')
            ->addOption('method', 'm', InputOption::VALUE_REQUIRED, 'Filter by HTTP method')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by schema type: request or response')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: json-schema or openapi', self::FORMAT_JSON_SCHEMA)
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (default: stdout)')
            ->addOption('no-pretty', null, InputOption::VALUE_NONE, 'Disable pretty-printed JSON output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $schema = $this->resolveSchema($input, $io);
        if ($schema === null) {
            return Command::FAILURE;
        }

        /** @var string $format */
        $format = $input->getOption('format');
        if (!in_array($format, [self::FORMAT_JSON_SCHEMA, self::FORMAT_OPENAPI], true)) {
            $io->error(sprintf(
                'Invalid format: %s. Valid values: %s, %s',
                $format,
                self::FORMAT_JSON_SCHEMA,
                self::FORMAT_OPENAPI
            ));
            return Command::FAILURE;
        }

        $exportData = $format === self::FORMAT_OPENAPI
            ? $this->toOpenApiSnippet($schema)
            : $schema->getJsonSchema();

        $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (!$input->getOption('no-pretty')) {
            $jsonFlags |= JSON_PRETTY_PRINT;
        }

        $jsonOutput = json_encode($exportData, $jsonFlags);
        if ($jsonOutput === false) {
            $io->error('Failed to encode schema as JSON.');
            return Command::FAILURE;
        }

        /** @var string|null $outputPath */
        $outputPath = $input->getOption('output');
        if ($outputPath !== null) {
            $result = file_put_contents($outputPath, $jsonOutput . "\n");
            if ($result === false) {
                $io->error(sprintf('Failed to write to file: %s', $outputPath));
                return Command::FAILURE;
            }

            $io->success(sprintf('Schema exported to %s', $outputPath));
            $this->printSchemaInfo($schema, $format, $io);
            return Command::SUCCESS;
        }

        $output->writeln($jsonOutput);
        return Command::SUCCESS;
    }

    private function resolveSchema(InputInterface $input, SymfonyStyle $io): ?ApiSchema
    {
        /** @var string|null $schemaId */
        $schemaId = $input->getOption('id');

        if ($schemaId !== null) {
            return $this->resolveSchemaById($schemaId, $io);
        }

        return $this->resolveSchemaByFilters($input, $io);
    }

    private function resolveSchemaById(string $schemaId, SymfonyStyle $io): ?ApiSchema
    {
        if (!Uuid::isValid($schemaId)) {
            $io->error(sprintf('Invalid UUID: %s', $schemaId));
            return null;
        }

        $schema = $this->schemaRepository->find(Uuid::fromString($schemaId));
        if ($schema === null) {
            $io->error(sprintf('Schema not found: %s', $schemaId));
            return null;
        }

        return $schema;
    }

    private function resolveSchemaByFilters(InputInterface $input, SymfonyStyle $io): ?ApiSchema
    {
        $required = ['token', 'host', 'endpoint', 'method', 'type'];
        $missing = [];

        foreach ($required as $option) {
            if ($input->getOption($option) === null) {
                $missing[] = '--' . $option;
            }
        }

        if (count($missing) > 0) {
            $io->error(sprintf(
                'Either --id or all filter options (%s) are required.',
                implode(', ', array_map(fn ($o) => '--' . $o, $required))
            ));
            return null;
        }

        /** @var string $tokenInput */
        $tokenInput = $input->getOption('token');
        $tokenId = $this->resolveTokenId($tokenInput);
        if ($tokenId === null) {
            $io->error(sprintf('Token not found: %s', $tokenInput));
            return null;
        }

        /** @var string $typeInput */
        $typeInput = $input->getOption('type');
        $schemaType = SchemaType::tryFrom($typeInput);
        if ($schemaType === null) {
            $io->error(sprintf(
                'Invalid schema type: %s. Valid values: %s',
                $typeInput,
                implode(', ', SchemaType::values())
            ));
            return null;
        }

        /** @var string $host */
        $host = $input->getOption('host');
        /** @var string $endpoint */
        $endpoint = $input->getOption('endpoint');
        /** @var string $methodInput */
        $methodInput = $input->getOption('method');
        $method = strtoupper($methodInput);

        $schema = $this->schemaRepository->findMasterSchema(
            $tokenId,
            $host,
            $endpoint,
            $method,
            $schemaType,
        );

        if ($schema === null) {
            $io->error(sprintf(
                'No master schema found for %s %s%s (%s)',
                $method,
                $host,
                $endpoint,
                $schemaType->value
            ));
            return null;
        }

        return $schema;
    }

    private function resolveTokenId(string $tokenInput): ?Uuid
    {
        if (Uuid::isValid($tokenInput)) {
            $token = $this->tokenRepository->find(Uuid::fromString($tokenInput));
            return $token?->getId();
        }

        $token = $this->tokenRepository->findOneBy(['name' => $tokenInput]);
        return $token?->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function toOpenApiSnippet(ApiSchema $schema): array
    {
        $method = strtolower($schema->getHttpMethod());
        $path = $schema->getEndpointPath();
        $jsonSchema = $schema->getJsonSchema();

        if ($schema->getSchemaType() === SchemaType::Request) {
            $operation = [
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $jsonSchema,
                        ],
                    ],
                ],
            ];
        } else {
            $operation = [
                'responses' => [
                    '200' => [
                        'description' => 'Response',
                        'content' => [
                            'application/json' => [
                                'schema' => $jsonSchema,
                            ],
                        ],
                    ],
                ],
            ];
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => sprintf('Schema for %s %s', strtoupper($method), $path),
                'version' => (string) $schema->getVersion(),
            ],
            'paths' => [
                $path => [
                    $method => $operation,
                ],
            ],
        ];
    }

    private function printSchemaInfo(ApiSchema $schema, string $format, SymfonyStyle $io): void
    {
        $io->table(
            ['Property', 'Value'],
            [
                ['Schema ID', $schema->getId()->toRfc4122()],
                ['Token', $schema->getToken()->getName()],
                ['Endpoint', sprintf('%s %s%s', $schema->getHttpMethod(), $schema->getTargetHost(), $schema->getEndpointPath())],
                ['Schema Type', $schema->getSchemaType()->value],
                ['Version', (string) $schema->getVersion()],
                ['Format', $format],
            ]
        );
    }
}
