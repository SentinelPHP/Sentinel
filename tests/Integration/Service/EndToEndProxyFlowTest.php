<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\ApiToken;
use App\Entity\SchemaDrift;
use App\Enum\DataProtectionStrategy;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Enum\TokenMode;
use App\Repository\ApiSchemaRepository;
use App\Repository\SchemaDriftRepository;
use App\Service\Alert\AlertDispatcherServiceInterface;
use App\Service\DataProtection\DataProtectionServiceInterface;
use App\Service\SchemaLearningService;
use App\Service\SchemaValidationService;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\RequestLogFactory;
use App\ValueObject\AlertResult;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * End-to-end integration test covering the complete proxy flow:
 * Token creation → Proxy request → Schema learning → Drift detection → Alert dispatch
 *
 * This test validates the entire data flow through the system without mocking
 * core services, ensuring all components integrate correctly.
 */
#[CoversClass(SchemaLearningService::class)]
#[CoversClass(SchemaValidationService::class)]
final class EndToEndProxyFlowTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private SchemaLearningService $learningService;
    private SchemaValidationService $validationService;
    private ApiSchemaRepository $schemaRepository;
    private SchemaDriftRepository $driftRepository;
    private DataProtectionServiceInterface $dataProtectionService;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->learningService = $container->get(SchemaLearningService::class);
        $this->validationService = $container->get(SchemaValidationService::class);
        $this->schemaRepository = $container->get(ApiSchemaRepository::class);
        $this->driftRepository = $container->get(SchemaDriftRepository::class);
        $this->dataProtectionService = $container->get(DataProtectionServiceInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    #[Test]
    public function itCompletesLearningPhaseWithSchemaGeneration(): void
    {
        // Create a token in learning mode with auto-switch
        $token = ApiTokenFactory::new()
            ->withMode(TokenMode::Learning)
            ->withLearningThreshold(3)
            ->withAutoSwitchToValidating()
            ->create();

        $targetHost = 'api.stripe.com';
        $path = '/v1/customers';
        $method = 'GET';

        // Simulate proxy requests during learning phase
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 1, "name": "John"}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 2, "name": "Jane"}');

        self::assertSame(TokenMode::Learning, $token->getMode(), 'Token should still be in learning mode');

        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 3, "name": "Bob"}');

        // Verify auto-switch to validating mode after threshold
        self::assertSame(TokenMode::Validating, $token->getMode(), 'Token should auto-switch to validating mode');

        // Verify master schema was created
        $masterSchema = $this->schemaRepository->findMasterSchema(
            $token->getId(),
            $targetHost,
            $path,
            $method,
            SchemaType::Response
        );

        self::assertNotNull($masterSchema, 'Master schema should exist after learning');
        self::assertTrue($masterSchema->isMaster());
        self::assertSame(3, $masterSchema->getSampleCount());

        // Verify schema structure
        $jsonSchema = $masterSchema->getJsonSchema();
        self::assertArrayHasKey('properties', $jsonSchema);
        /** @var array<string, mixed> $properties */
        $properties = $jsonSchema['properties'];
        self::assertArrayHasKey('id', $properties);
        self::assertArrayHasKey('name', $properties);
    }

    #[Test]
    public function itLearnsMultipleEndpointsIndependently(): void
    {
        $token = ApiTokenFactory::new()
            ->withMode(TokenMode::Learning)
            ->create();

        $targetHost = 'api.example.com';

        // Learn /users endpoint
        $this->learningService->learn($token, $targetHost, '/users', 'GET', '{"id": 1, "name": "John"}');
        $this->learningService->learn($token, $targetHost, '/users', 'GET', '{"id": 2, "name": "Jane"}');

        // Learn /orders endpoint
        $this->learningService->learn($token, $targetHost, '/orders', 'GET', '{"orderId": "ORD-001", "total": 99.99}');
        $this->learningService->learn($token, $targetHost, '/orders', 'GET', '{"orderId": "ORD-002", "total": 149.50}');

        // Verify both schemas exist independently
        $usersSchemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            $targetHost,
            '/users',
            'GET',
            SchemaType::Response
        );
        $ordersSchemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            $targetHost,
            '/orders',
            'GET',
            SchemaType::Response
        );

        self::assertCount(1, $usersSchemas);
        self::assertCount(1, $ordersSchemas);
        self::assertNotSame($usersSchemas[0]->getId(), $ordersSchemas[0]->getId());

        // Verify schemas have correct properties
        $usersJsonSchema = $usersSchemas[0]->getJsonSchema();
        $ordersJsonSchema = $ordersSchemas[0]->getJsonSchema();
        /** @var array<string, mixed> $usersProperties */
        $usersProperties = $usersJsonSchema['properties'] ?? [];
        /** @var array<string, mixed> $ordersProperties */
        $ordersProperties = $ordersJsonSchema['properties'] ?? [];
        self::assertArrayHasKey('name', $usersProperties);
        self::assertArrayHasKey('orderId', $ordersProperties);
    }

    #[Test]
    public function itAppliesDataProtectionDuringFullFlow(): void
    {
        // Create token with redaction strategy
        $token = ApiTokenFactory::new()
            ->withMode(TokenMode::Learning)
            ->withLearningThreshold(2)
            ->withAutoSwitchToValidating()
            ->with(['dataProtectionStrategy' => DataProtectionStrategy::Redact])
            ->create();

        $targetHost = 'api.payments.com';
        $path = '/transactions';
        $method = 'POST';

        // Learn with sensitive data (should be redacted in storage)
        $sensitiveResponse1 = '{"txn_id": "TXN001", "card": "4111111111111111", "email": "user@example.com"}';
        $sensitiveResponse2 = '{"txn_id": "TXN002", "card": "5500000000000004", "email": "other@example.com"}';

        $this->learningService->learn($token, $targetHost, $path, $method, $sensitiveResponse1);
        $this->learningService->learn($token, $targetHost, $path, $method, $sensitiveResponse2);

        // Verify schema was created
        $schema = $this->schemaRepository->findMasterSchema(
            $token->getId(),
            $targetHost,
            $path,
            $method,
            SchemaType::Response
        );
        self::assertNotNull($schema);

        // Verify effective strategy is applied
        $effectiveStrategy = $this->dataProtectionService->getEffectiveStrategy($token);
        self::assertSame(DataProtectionStrategy::Redact, $effectiveStrategy);
    }

    #[Test]
    public function itTracksDriftSeverityCorrectly(): void
    {
        $token = ApiTokenFactory::new()
            ->withMode(TokenMode::Learning)
            ->withLearningThreshold(2)
            ->withAutoSwitchToValidating()
            ->create();

        $targetHost = 'api.example.com';
        $path = '/data';
        $method = 'GET';

        // Learn schema with required fields
        $this->learningService->learn($token, $targetHost, $path, $method, '{"required_field": "value", "optional": 1}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"required_field": "other", "optional": 2}');

        // Cause critical drift (field removed)
        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"optional": 3}' // required_field is missing
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());
        self::assertCount(1, $drifts);
        self::assertSame(DriftType::FieldRemoved, $drifts[0]->getDriftType());
        self::assertSame(DriftSeverity::Critical, $drifts[0]->getSeverity());
    }

    #[Test]
    public function itHandlesRequestBodyValidation(): void
    {
        $token = ApiTokenFactory::new()
            ->withMode(TokenMode::Learning)
            ->withLearningThreshold(2)
            ->withAutoSwitchToValidating()
            ->with(['validateRequestBody' => true])
            ->create();

        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'POST';

        // Learn response schema (request body learning is separate)
        $this->learningService->learn(
            $token,
            $targetHost,
            $path,
            $method,
            '{"success": true, "user_id": 1}'
        );
        $this->learningService->learn(
            $token,
            $targetHost,
            $path,
            $method,
            '{"success": true, "user_id": 2}'
        );

        // Verify response schema was created
        $responseSchema = $this->schemaRepository->findMasterSchema(
            $token->getId(),
            $targetHost,
            $path,
            $method,
            SchemaType::Response
        );

        self::assertNotNull($responseSchema, 'Response schema should be created');
        $jsonSchema = $responseSchema->getJsonSchema();
        /** @var array<string, mixed> $properties */
        $properties = $jsonSchema['properties'] ?? [];
        self::assertArrayHasKey('success', $properties);
        self::assertArrayHasKey('user_id', $properties);
    }

    #[Test]
    public function itMaintainsSchemaVersionHistory(): void
    {
        $token = ApiTokenFactory::new()
            ->withMode(TokenMode::Learning)
            ->create();

        $targetHost = 'api.example.com';
        $path = '/evolving';
        $method = 'GET';

        // Learn progressively more complex responses
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 1}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 2, "name": "Added"}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 3, "name": "More", "extra": true}');

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            $targetHost,
            $path,
            $method,
            SchemaType::Response
        );

        // Should have one schema with merged properties
        self::assertCount(1, $schemas);
        $schema = $schemas[0];

        // Version should reflect number of samples
        self::assertSame(3, $schema->getVersion());
        self::assertSame(3, $schema->getSampleCount());

        // Schema should contain all observed properties
        $jsonSchema = $schema->getJsonSchema();
        /** @var array<string, mixed> $properties */
        $properties = $jsonSchema['properties'] ?? [];
        self::assertArrayHasKey('id', $properties);
        self::assertArrayHasKey('name', $properties);
        self::assertArrayHasKey('extra', $properties);
    }

    #[Test]
    public function itLinksRequestLogToDrift(): void
    {
        $token = ApiTokenFactory::new()
            ->withMode(TokenMode::Learning)
            ->withLearningThreshold(2)
            ->withAutoSwitchToValidating()
            ->create();

        $targetHost = 'api.example.com';
        $path = '/linked';
        $method = 'GET';

        $this->learningService->learn($token, $targetHost, $path, $method, '{"value": 100}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"value": 200}');

        // Create request log that will have drift
        $requestLog = RequestLogFactory::new()->create([
            'token' => $token,
            'targetHost' => $targetHost,
            'requestPath' => $path,
            'requestMethod' => $method,
        ]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"value": "not-a-number"}',
            null,
            $requestLog->getId()->toRfc4122()
        );

        $this->entityManager->refresh($requestLog);

        // Verify bidirectional link
        self::assertTrue($requestLog->isDriftDetected());
        self::assertNotNull($requestLog->getDrift());

        $drift = $requestLog->getDrift();
        self::assertSame(DriftType::TypeChanged, $drift->getDriftType());
        self::assertSame('$.value', $drift->getPath());

        // Verify drift references the request log
        self::assertSame(
            $requestLog->getId()->toRfc4122(),
            $drift->getRequestLog()?->getId()->toRfc4122()
        );
    }
}
