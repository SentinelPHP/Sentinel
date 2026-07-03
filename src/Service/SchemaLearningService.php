<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Enum\TokenMode;
use App\Repository\ApiSchemaRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SentinelPHP\Schema\Config\GeneratorConfig;
use SentinelPHP\Schema\GeneratorInterface;
use SentinelPHP\Schema\MergerInterface;

final class SchemaLearningService implements SchemaLearningServiceInterface
{
    public function __construct(
        private readonly GeneratorInterface $schemaGenerator,
        private readonly MergerInterface $schemaMerger,
        private readonly ApiSchemaRepositoryInterface $schemaRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function learn(
        ApiToken $token,
        string $targetHost,
        string $path,
        string $method,
        string $responseBody,
    ): void {
        if ($token->getMode() !== TokenMode::Learning) {
            return;
        }

        $payload = $this->decodeJson($responseBody);
        if ($payload === null) {
            return;
        }

        /** @var array<string, mixed>|list<mixed> $payload */
        $generatedSchema = $this->schemaGenerator->generate(
            $payload,
            GeneratorConfig::strict()
        );

        $existingSchema = $this->schemaRepository->findLatestLearned(
            $token->getId(),
            $targetHost,
            $path,
            strtoupper($method),
            SchemaType::Response
        );

        if ($existingSchema === null) {
            $existingSchema = $this->createNewSchema($token, $targetHost, $path, $method, $generatedSchema);
        } else {
            $this->updateExistingSchema($existingSchema, $generatedSchema);
        }

        $this->checkAutoPromotion($token, $existingSchema);

        $this->entityManager->flush();
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function decodeJson(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return null;
            }
            /** @var array<int|string, mixed> $decoded */
            return $decoded;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function createNewSchema(
        ApiToken $token,
        string $targetHost,
        string $path,
        string $method,
        array $schema,
    ): ApiSchema {
        $apiSchema = new ApiSchema();
        $apiSchema->setToken($token)
            ->setTargetHost($targetHost)
            ->setEndpointPath($path)
            ->setHttpMethod($method)
            ->setSchemaType(SchemaType::Response)
            ->setJsonSchema($schema)
            ->setVersion(1)
            ->setIsMaster(false);

        $this->entityManager->persist($apiSchema);

        return $apiSchema;
    }

    /**
     * @param array<string, mixed> $newSchema
     */
    private function updateExistingSchema(ApiSchema $existingSchema, array $newSchema): void
    {
        $mergedSchema = $this->mergeSchemas($existingSchema->getJsonSchema(), $newSchema);
        $existingSchema->setJsonSchema($mergedSchema);
        $existingSchema->incrementVersion();
        $existingSchema->incrementSampleCount();
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     * @return array<string, mixed>
     */
    private function mergeSchemas(array $existing, array $new): array
    {
        return $this->schemaMerger->merge($existing, $new);
    }

    private function checkAutoPromotion(ApiToken $token, ApiSchema $schema): void
    {
        $threshold = $token->getLearningThreshold();

        if ($threshold === null || $threshold <= 0) {
            return;
        }

        if ($schema->isMaster()) {
            return;
        }

        if (!$schema->isStable($threshold)) {
            return;
        }

        $schema->setIsMaster(true);

        $this->logger?->info('Schema auto-promoted to master', [
            'schema_id' => $schema->getId()->toRfc4122(),
            'token_id' => $token->getId()->toRfc4122(),
            'endpoint' => sprintf('%s %s%s', $schema->getHttpMethod(), $schema->getTargetHost(), $schema->getEndpointPath()),
            'sample_count' => $schema->getSampleCount(),
            'threshold' => $threshold,
        ]);

        if ($token->isAutoSwitchToValidating()) {
            $token->setMode(TokenMode::Validating);

            $this->logger?->info('Token auto-switched to validating mode', [
                'token_id' => $token->getId()->toRfc4122(),
            ]);
        }
    }
}
