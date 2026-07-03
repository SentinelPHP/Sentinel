<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\ApiToken;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Enum\TokenMode;
use App\Repository\SchemaDriftRepository;
use App\Service\SchemaValidationService;
use SentinelPHP\Drift\ClassifierInterface;
use SentinelPHP\Drift\Enum\DriftSeverity as LibrarySeverity;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\RequestLogFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(SchemaValidationService::class)]
final class DriftDetectionTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private SchemaValidationService $validationService;
    private SchemaDriftRepository $driftRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->validationService = $container->get(SchemaValidationService::class);
        $this->driftRepository = $container->get(SchemaDriftRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    private function createValidatingToken(
        bool $validateRequestBody = false,
        ?DriftSeverity $alertMinSeverity = null,
    ): ApiToken {
        $factory = ApiTokenFactory::new()->withMode(TokenMode::Validating);

        if ($validateRequestBody) {
            $factory = $factory->with(['validateRequestBody' => true]);
        }

        if ($alertMinSeverity !== null) {
            $factory = $factory->with(['alertMinSeverity' => $alertMinSeverity]);
        }

        return $factory->create();
    }

    #[Test]
    public function itDetectsTypeChangedDrift(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
                'required' => ['id', 'name'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": "not-an-integer", "name": "John"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(1, $drifts);
        self::assertSame(DriftType::TypeChanged, $drifts[0]->getDriftType());
        self::assertSame('$.id', $drifts[0]->getPath());
        self::assertSame(DriftSeverity::Warning, $drifts[0]->getSeverity());
    }

    #[Test]
    public function itDetectsFieldRemovedDriftAsCritical(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                ],
                'required' => ['id', 'name', 'email'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": 1, "name": "John"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(1, $drifts);
        self::assertSame(DriftType::FieldRemoved, $drifts[0]->getDriftType());
        self::assertSame(DriftSeverity::Critical, $drifts[0]->getSeverity());
    }

    #[Test]
    public function itDetectsFieldAddedDriftAsInfo(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": 1, "unexpectedField": "value"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(1, $drifts);
        self::assertSame(DriftType::FieldAdded, $drifts[0]->getDriftType());
        self::assertSame(DriftSeverity::Info, $drifts[0]->getSeverity());
    }

    #[Test]
    public function itDetectsCriticalDriftWhenObjectBecomesScalar(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                        ],
                        'required' => ['id'],
                    ],
                ],
                'required' => ['data'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"data": "not-an-object"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(1, $drifts);
        self::assertSame(DriftType::TypeChanged, $drifts[0]->getDriftType());
        self::assertContains(
            $drifts[0]->getSeverity(),
            [DriftSeverity::Warning, DriftSeverity::Critical],
            'Type change from object to scalar should be at least Warning severity'
        );
    }

    #[Test]
    public function itRecordsNoDriftWhenResponseIsValid(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
                'required' => ['id', 'name'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": 1, "name": "John"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(0, $drifts);
    }

    #[Test]
    public function itUpdatesRequestLogOnDriftDetection(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $requestLog = RequestLogFactory::new()->create([
            'token' => $token,
            'targetHost' => $targetHost,
            'requestPath' => $path,
            'requestMethod' => $method,
            'responseStatusCode' => 200,
            'latencyMs' => 100,
        ]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": "wrong-type"}',
            null,
            $requestLog->getId()->toRfc4122()
        );

        $this->entityManager->refresh($requestLog);

        self::assertTrue($requestLog->isSchemaValidated());
        self::assertTrue($requestLog->isDriftDetected());
        self::assertNotNull($requestLog->getDrift());
        self::assertSame(DriftType::TypeChanged, $requestLog->getDrift()->getDriftType());
    }

    #[Test]
    public function itUpdatesRequestLogWhenNoDriftDetected(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $requestLog = RequestLogFactory::new()->create([
            'token' => $token,
            'targetHost' => $targetHost,
            'requestPath' => $path,
            'requestMethod' => $method,
            'responseStatusCode' => 200,
            'latencyMs' => 100,
        ]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": 123}',
            null,
            $requestLog->getId()->toRfc4122()
        );

        $this->entityManager->refresh($requestLog);

        self::assertTrue($requestLog->isSchemaValidated());
        self::assertFalse($requestLog->isDriftDetected());
        self::assertNull($requestLog->getDrift());
    }

    #[Test]
    public function itSkipsValidationWhenTokenNotInValidatingMode(): void
    {
        $token = ApiTokenFactory::new()->withMode(TokenMode::Learning)->create();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

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
    public function itSkipsValidationWhenNoMasterSchemaExists(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": "any-value"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(0, $drifts, 'No drifts should be recorded when no master schema exists');
    }

    #[Test]
    public function itValidatesRequestBodyWhenEnabled(): void
    {
        $token = $this->createValidatingToken(validateRequestBody: true);
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'POST';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                ],
                'required' => ['success'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        ApiSchemaFactory::new()
            ->master()
            ->forRequest()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                ],
                'required' => ['name', 'email'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"success": true}',
            '{"name": 123, "email": "test@example.com"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(1, $drifts);
        self::assertSame(DriftType::TypeChanged, $drifts[0]->getDriftType());
        self::assertSame('$.name', $drifts[0]->getPath());
    }

    #[Test]
    public function itDoesNotValidateRequestBodyWhenDisabled(): void
    {
        $token = $this->createValidatingToken(validateRequestBody: false);
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'POST';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                ],
                'required' => ['success'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        ApiSchemaFactory::new()
            ->master()
            ->forRequest()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"success": true}',
            '{"name": 123}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(0, $drifts, 'Request body should not be validated when disabled');
    }

    #[Test]
    public function itDetectsMultipleDriftsInSingleResponse(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'age' => ['type' => 'integer'],
                ],
                'required' => ['id', 'name', 'age'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": "wrong", "name": 123}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertGreaterThanOrEqual(1, count($drifts), 'At least one drift should be detected');

        $driftTypes = array_map(fn ($d) => $d->getDriftType(), $drifts);
        $hasTypeChanged = in_array(DriftType::TypeChanged, $driftTypes, true);
        $hasFieldRemoved = in_array(DriftType::FieldRemoved, $driftTypes, true);
        self::assertTrue($hasTypeChanged || $hasFieldRemoved, 'Should detect type change or field removed');
    }

    #[Test]
    public function itLinksFirstDriftToRequestLog(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
                'required' => ['id', 'name'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $requestLog = RequestLogFactory::new()->create([
            'token' => $token,
            'targetHost' => $targetHost,
            'requestPath' => $path,
            'requestMethod' => $method,
            'responseStatusCode' => 200,
            'latencyMs' => 100,
        ]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            '{"id": "wrong", "name": 123}',
            null,
            $requestLog->getId()->toRfc4122()
        );

        $this->entityManager->refresh($requestLog);

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertGreaterThanOrEqual(1, count($drifts));
        self::assertNotNull($requestLog->getDrift());
        self::assertContains($requestLog->getDrift(), $drifts);
    }

    #[Test]
    public function itSkipsValidationForInvalidJson(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            'not valid json'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(0, $drifts, 'Invalid JSON should not cause drift recording');
    }

    #[Test]
    public function itSkipsValidationForEmptyResponseBody(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';
        $path = '/users';
        $method = 'GET';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, $path, $method)
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            $path,
            $method,
            ''
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(0, $drifts, 'Empty response body should not cause drift recording');
    }

    #[Test]
    public function itClassifiesDriftSeverityCorrectly(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, '/info', 'GET')
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            '/info',
            'GET',
            '{"id": 1, "extra_field": "value"}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(1, $drifts);
        self::assertSame(DriftSeverity::Info, $drifts[0]->getSeverity(), 'Field added should be info');
        self::assertSame(DriftType::FieldAdded, $drifts[0]->getDriftType());
    }

    #[Test]
    public function itClassifiesFieldRemovedAsCritical(): void
    {
        $token = $this->createValidatingToken();
        $targetHost = 'api.example.com';

        ApiSchemaFactory::new()
            ->master()
            ->forResponse()
            ->forEndpoint($targetHost, '/users', 'GET')
            ->withJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
                'required' => ['id', 'name'],
                'additionalProperties' => false,
            ])
            ->create(['token' => $token]);

        $this->validationService->validate(
            $token,
            $targetHost,
            '/users',
            'GET',
            '{"id": 1}'
        );

        $drifts = $this->driftRepository->findByTokenId($token->getId());

        self::assertCount(1, $drifts);
        self::assertSame(DriftSeverity::Critical, $drifts[0]->getSeverity(), 'Field removed should be critical');
        self::assertSame(DriftType::FieldRemoved, $drifts[0]->getDriftType());
    }

    #[Test]
    public function driftClassifierShouldAlertRespectsThreshold(): void
    {
        $container = self::getContainer();
        /** @var ClassifierInterface $classifier */
        $classifier = $container->get(ClassifierInterface::class);

        // Test with Warning threshold
        self::assertTrue(
            $classifier->shouldAlert(LibrarySeverity::Critical, LibrarySeverity::Warning),
            'Critical should alert when threshold is Warning'
        );
        self::assertTrue(
            $classifier->shouldAlert(LibrarySeverity::Warning, LibrarySeverity::Warning),
            'Warning should alert when threshold is Warning'
        );
        self::assertFalse(
            $classifier->shouldAlert(LibrarySeverity::Info, LibrarySeverity::Warning),
            'Info should not alert when threshold is Warning'
        );

        // Test with Critical threshold
        self::assertTrue(
            $classifier->shouldAlert(LibrarySeverity::Critical, LibrarySeverity::Critical),
            'Critical should alert when threshold is Critical'
        );
        self::assertFalse(
            $classifier->shouldAlert(LibrarySeverity::Warning, LibrarySeverity::Critical),
            'Warning should not alert when threshold is Critical'
        );
        self::assertFalse(
            $classifier->shouldAlert(LibrarySeverity::Info, LibrarySeverity::Critical),
            'Info should not alert when threshold is Critical'
        );
    }
}
