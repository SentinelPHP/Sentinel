<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\RequestLog;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Enum\TokenMode;
use App\Repository\ApiSchemaRepository;
use App\Repository\SchemaDriftRepository;
use App\Service\SchemaLearningService;
use App\Service\SchemaValidationService;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\RequestLogFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(SchemaLearningService::class)]
#[CoversClass(SchemaValidationService::class)]
final class SchemaLearningValidationCycleTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private SchemaLearningService $learningService;
    private SchemaValidationService $validationService;
    private ApiSchemaRepository $schemaRepository;
    private SchemaDriftRepository $driftRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->learningService = $container->get(SchemaLearningService::class);
        $this->validationService = $container->get(SchemaValidationService::class);
        $this->schemaRepository = $container->get(ApiSchemaRepository::class);
        $this->driftRepository = $container->get(SchemaDriftRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    private function createToken(
        TokenMode $mode = TokenMode::Learning,
        ?int $learningThreshold = null,
        bool $autoSwitchToValidating = false,
    ): ApiToken {
        $factory = ApiTokenFactory::new()->withMode($mode);

        if ($learningThreshold !== null) {
            $factory = $factory->withLearningThreshold($learningThreshold);
        }

        if ($autoSwitchToValidating) {
            $factory = $factory->withAutoSwitchToValidating();
        }

        return $factory->create();
    }

    #[Test]
    public function itCompletesFullLearningToValidationCycle(): void
    {
        $token = $this->createToken(
            TokenMode::Learning,
            learningThreshold: 3,
            autoSwitchToValidating: true
        );

        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 1, "name": "John"}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 2, "name": "Jane"}');

        self::assertSame(TokenMode::Learning, $token->getMode(), 'Token should still be in learning mode');

        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 3, "name": "Bob"}');

        self::assertSame(TokenMode::Validating, $token->getMode(), 'Token should switch to validating mode');

        $schema = $this->schemaRepository->findMasterSchema(
            $token->getId(),
            $targetHost,
            $path,
            $method,
            SchemaType::Response
        );
        self::assertNotNull($schema);
        self::assertTrue($schema->isMaster(), 'Schema should be promoted to master');

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": 4, "name": "Alice"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());
        self::assertCount(0, $drifts, 'No drifts should be recorded for valid response');
    }

    #[Test]
    public function itDetectsDriftAfterSchemaIsPromoted(): void
    {
        $token = $this->createToken(
            TokenMode::Learning,
            learningThreshold: 2,
            autoSwitchToValidating: true
        );

        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 1, "name": "John"}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 2, "name": "Jane"}');

        self::assertSame(TokenMode::Validating, $token->getMode());

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": "not-an-integer", "name": "Bob"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());
        self::assertCount(1, $drifts, 'One drift should be recorded for type change');

        $drift = $drifts[0];
        self::assertSame(DriftType::TypeChanged, $drift->getDriftType());
        self::assertSame('$.id', $drift->getPath());
    }

    #[Test]
    public function itDetectsDriftWhenFieldIsRemoved(): void
    {
        $token = $this->createToken(
            TokenMode::Learning,
            learningThreshold: 2,
            autoSwitchToValidating: true
        );

        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 1, "name": "John", "email": "john@example.com"}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 2, "name": "Jane", "email": "jane@example.com"}');

        self::assertSame(TokenMode::Validating, $token->getMode());

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": 3, "name": "Bob"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());
        self::assertCount(1, $drifts, 'One drift should be recorded for missing field');

        $drift = $drifts[0];
        self::assertSame(DriftType::FieldRemoved, $drift->getDriftType());
        self::assertSame(DriftSeverity::Critical, $drift->getSeverity());
    }

    #[Test]
    public function itDetectsDriftWhenFieldIsAdded(): void
    {
        $token = $this->createToken(
            TokenMode::Learning,
            learningThreshold: 2,
            autoSwitchToValidating: true
        );

        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 1}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 2}');

        self::assertSame(TokenMode::Validating, $token->getMode());

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": 3, "unexpectedField": "value"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());
        self::assertCount(1, $drifts, 'One drift should be recorded for added field');

        $drift = $drifts[0];
        self::assertSame(DriftType::FieldAdded, $drift->getDriftType());
        self::assertSame(DriftSeverity::Info, $drift->getSeverity());
    }

    #[Test]
    public function itMergesSchemasDuringLearning(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 1}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 2, "name": "John"}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 3, "name": "Jane", "email": "jane@example.com"}');

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            $targetHost,
            $path,
            $method,
            SchemaType::Response
        );

        self::assertCount(1, $schemas);

        $schema = $schemas[0];
        $jsonSchema = $schema->getJsonSchema();

        self::assertArrayHasKey('properties', $jsonSchema);
        self::assertIsArray($jsonSchema['properties']);
        self::assertArrayHasKey('id', $jsonSchema['properties']);
        self::assertArrayHasKey('name', $jsonSchema['properties']);
        self::assertArrayHasKey('email', $jsonSchema['properties']);

        self::assertSame(3, $schema->getSampleCount());
        self::assertSame(3, $schema->getVersion());
    }

    #[Test]
    public function itUpdatesRequestLogWithValidationStatusWhenValid(): void
    {
        $token = $this->createToken(
            TokenMode::Learning,
            learningThreshold: 2,
            autoSwitchToValidating: true
        );

        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 1}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 2}');

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
            '{"id": 3}',
            null,
            $requestLog->getId()->toRfc4122()
        );

        $this->entityManager->refresh($requestLog);

        self::assertTrue($requestLog->isSchemaValidated(), 'Request log should be marked as validated');
        self::assertFalse($requestLog->isDriftDetected(), 'No drift should be detected');
        self::assertNull($requestLog->getDrift(), 'No drift should be linked');
    }

    #[Test]
    public function itUpdatesRequestLogWithValidationStatusWhenDriftDetected(): void
    {
        $token = $this->createToken(
            TokenMode::Learning,
            learningThreshold: 2,
            autoSwitchToValidating: true
        );

        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 1}');
        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 2}');

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
            '{"id": "invalid-type"}',
            null,
            $requestLog->getId()->toRfc4122()
        );

        $this->entityManager->refresh($requestLog);

        self::assertTrue($requestLog->isSchemaValidated(), 'Request log should be marked as validated');
        self::assertTrue($requestLog->isDriftDetected(), 'Drift should be detected');
        self::assertNotNull($requestLog->getDrift(), 'Drift should be linked to request log');
    }

    #[Test]
    public function itSkipsValidationWhenNoMasterSchemaExists(): void
    {
        $token = $this->createToken(TokenMode::Validating);

        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": 1, "name": "John"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());
        self::assertCount(0, $drifts, 'No drifts should be recorded when no master schema exists');
    }

    #[Test]
    public function itHandlesMultipleEndpointsIndependently(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        $targetHost = 'api.example.com';

        $this->learningService->learn($token, $targetHost, '/users', 'GET', '{"id": 1, "name": "John"}');
        $this->learningService->learn($token, $targetHost, '/users', 'GET', '{"id": 2, "name": "Jane"}');

        $this->learningService->learn($token, $targetHost, '/orders', 'GET', '{"orderId": 100, "total": 99.99}');
        $this->learningService->learn($token, $targetHost, '/orders', 'GET', '{"orderId": 101, "total": 149.99}');

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

        $usersSchema = $usersSchemas[0];
        $ordersSchema = $ordersSchemas[0];

        $usersSchema->setIsMaster(true);
        $ordersSchema->setIsMaster(true);
        $token->setMode(TokenMode::Validating);
        $this->entityManager->flush();

        $this->validationService->validate(
            $token,
            $targetHost,
            '/users',
            'GET',
            '{"id": "wrong-type", "name": "Bob"}'
        );

        $this->validationService->validate(
            $token,
            $targetHost,
            '/orders',
            'GET',
            '{"orderId": 102, "total": "wrong-type"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());
        self::assertCount(2, $drifts, 'Two drifts should be recorded');

        $usersDrift = array_filter($drifts, fn ($d) => $d->getSchema()->getEndpointPath() === '/users');
        $ordersDrift = array_filter($drifts, fn ($d) => $d->getSchema()->getEndpointPath() === '/orders');

        self::assertCount(1, $usersDrift);
        self::assertCount(1, $ordersDrift);

        self::assertSame('$.id', array_values($usersDrift)[0]->getPath());
        self::assertSame('$.total', array_values($ordersDrift)[0]->getPath());
    }

    #[Test]
    public function itDoesNotValidateWhenTokenIsInLearningMode(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 1}');

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            $targetHost,
            $path,
            $method,
            SchemaType::Response
        );
        $schemas[0]->setIsMaster(true);
        $this->entityManager->flush();

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": "wrong-type"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());
        self::assertCount(0, $drifts, 'No validation should occur in learning mode');
    }

    #[Test]
    public function itDoesNotLearnWhenTokenIsInValidatingMode(): void
    {
        $token = $this->createToken(TokenMode::Validating);

        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        $this->learningService->learn($token, $targetHost, $path, $method, '{"id": 1}');

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            $targetHost,
            $path,
            $method,
            SchemaType::Response
        );

        self::assertCount(0, $schemas, 'No schema should be created in validating mode');
    }
}
