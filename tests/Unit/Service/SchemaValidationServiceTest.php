<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\RequestLog;
use App\Entity\SchemaDrift;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use App\Enum\LogLevel;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Enum\TokenMode;
use App\Message\DriftPayloadMessage;
use App\Repository\ApiSchemaRepositoryInterface;
use App\Service\Alert\AlertDispatcherServiceInterface;
use App\Service\SchemaValidationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use SentinelPHP\Drift\Classifier;
use SentinelPHP\Drift\ClassifierInterface;
use SentinelPHP\Schema\Validation\ValidationError;
use SentinelPHP\Schema\Validation\ValidationResult;
use SentinelPHP\Schema\ValidatorInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SchemaValidationService::class)]
#[AllowMockObjectsWithoutExpectations]
final class SchemaValidationServiceTest extends TestCase
{
    private ValidatorInterface&MockObject $schemaValidator;
    private ApiSchemaRepositoryInterface&MockObject $schemaRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private ClassifierInterface $driftClassifier;
    private MessageBusInterface&MockObject $messageBus;
    private AlertDispatcherServiceInterface&MockObject $alertDispatcher;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private SchemaValidationService $service;

    protected function setUp(): void
    {
        $this->schemaValidator = $this->createMock(ValidatorInterface::class);
        $this->schemaRepository = $this->createMock(ApiSchemaRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('contains')->willReturn(true);
        $this->driftClassifier = new Classifier();
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->method('dispatch')->willReturnCallback(
            fn (object $message) => new Envelope($message)
        );
        $this->alertDispatcher = $this->createMock(AlertDispatcherServiceInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->service = new SchemaValidationService(
            $this->schemaValidator,
            $this->schemaRepository,
            $this->entityManager,
            $this->driftClassifier,
            $this->messageBus,
            $this->alertDispatcher,
            $this->eventDispatcher,
        );
    }

    #[Test]
    public function itSkipsValidationWhenTokenModeIsNotValidating(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        $this->schemaRepository->expects(self::never())->method('findMasterSchema');
        $this->schemaValidator->expects(self::never())->method('validate');
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1}'
        );
    }

    #[Test]
    public function itSkipsValidationWhenTokenModeIsPassive(): void
    {
        $token = $this->createToken(TokenMode::Passive);

        $this->schemaRepository->expects(self::never())->method('findMasterSchema');
        $this->schemaValidator->expects(self::never())->method('validate');
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1}'
        );
    }

    #[Test]
    public function itSkipsValidationWhenNoMasterSchemaExists(): void
    {
        $token = $this->createToken(TokenMode::Validating);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn(null);

        $this->schemaValidator->expects(self::never())->method('validate');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1}'
        );
    }

    #[Test]
    public function itSkipsValidationWhenResponseBodyIsEmpty(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $this->schemaValidator->expects(self::never())->method('validate');
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            ''
        );
    }

    #[Test]
    public function itSkipsValidationWhenResponseBodyIsNotJson(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $this->schemaValidator->expects(self::never())->method('validate');
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            'not valid json'
        );
    }

    #[Test]
    public function itDoesNotRecordDriftWhenValidationPasses(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::valid());

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1}'
        );
    }

    #[Test]
    public function itRecordsDriftWhenValidationFails(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $validationError = new ValidationError(
            path: '$.name',
            message: 'The data must be a string',
            keyword: 'type',
            expected: 'string',
            actual: 'integer',
        );

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::invalid([$validationError]));

        $persistedDrift = null;
        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (SchemaDrift $drift) use (&$persistedDrift): bool {
                $persistedDrift = $drift;
                return true;
            }));

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"name": 123}'
        );

        self::assertNotNull($persistedDrift);
        self::assertSame($schema, $persistedDrift->getSchema());
        self::assertSame($token, $persistedDrift->getToken());
        self::assertSame(DriftType::TypeChanged, $persistedDrift->getDriftType());
        self::assertSame('$.name', $persistedDrift->getPath());
        self::assertSame(['value' => 'string'], $persistedDrift->getExpectedValue());
        self::assertSame(['value' => 'integer'], $persistedDrift->getActualValue());
        self::assertSame(DriftSeverity::Warning, $persistedDrift->getSeverity());
    }

    #[Test]
    public function itClassifiesFieldRemovedAsCritical(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $validationError = new ValidationError(
            path: '$.email',
            message: 'Missing required field',
            keyword: 'required',
            expected: ['email'],
            actual: null,
        );

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::invalid([$validationError]));

        $persistedDrift = null;
        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (SchemaDrift $drift) use (&$persistedDrift): bool {
                $persistedDrift = $drift;
                return true;
            }));

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"name": "John"}'
        );

        self::assertNotNull($persistedDrift);
        self::assertSame(DriftType::FieldRemoved, $persistedDrift->getDriftType());
        self::assertSame(DriftSeverity::Critical, $persistedDrift->getSeverity());
    }

    #[Test]
    public function itClassifiesFieldAddedAsInfo(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $validationError = new ValidationError(
            path: '$.extra',
            message: 'Additional property not allowed',
            keyword: 'additionalProperties',
            expected: false,
            actual: ['extra'],
        );

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::invalid([$validationError]));

        $persistedDrift = null;
        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (SchemaDrift $drift) use (&$persistedDrift): bool {
                $persistedDrift = $drift;
                return true;
            }));

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1, "name": "John", "extra": "field"}'
        );

        self::assertNotNull($persistedDrift);
        self::assertSame(DriftType::FieldAdded, $persistedDrift->getDriftType());
        self::assertSame(DriftSeverity::Info, $persistedDrift->getSeverity());
    }

    #[Test]
    public function itClassifiesObjectToPrimitiveTypeChangeAsCritical(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $validationError = new ValidationError(
            path: '$.data',
            message: 'Expected object, got string',
            keyword: 'type',
            expected: 'object',
            actual: 'string',
        );

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::invalid([$validationError]));

        $persistedDrift = null;
        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (SchemaDrift $drift) use (&$persistedDrift): bool {
                $persistedDrift = $drift;
                return true;
            }));

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"data": "not an object"}'
        );

        self::assertNotNull($persistedDrift);
        self::assertSame(DriftType::TypeChanged, $persistedDrift->getDriftType());
        self::assertSame(DriftSeverity::Critical, $persistedDrift->getSeverity());
    }

    #[Test]
    public function itRecordsMultipleDriftsForMultipleErrors(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $errors = [
            new ValidationError('$.name', 'Type error', 'type', 'string', 'integer'),
            new ValidationError('$.email', 'Missing field', 'required', ['email'], null),
            new ValidationError('$.extra', 'Extra field', 'additionalProperties', false, ['extra']),
        ];

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::invalid($errors));

        $persistedDrifts = [];
        $this->entityManager
            ->expects(self::exactly(3))
            ->method('persist')
            ->with(self::callback(function (SchemaDrift $drift) use (&$persistedDrifts): bool {
                $persistedDrifts[] = $drift;
                return true;
            }));

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"name": 123, "extra": "field"}'
        );

        self::assertCount(3, $persistedDrifts);
        self::assertSame(DriftType::TypeChanged, $persistedDrifts[0]->getDriftType());
        self::assertSame(DriftType::FieldRemoved, $persistedDrifts[1]->getDriftType());
        self::assertSame(DriftType::FieldAdded, $persistedDrifts[2]->getDriftType());
    }

    #[Test]
    public function itMapsKeywordsToDriftTypesCorrectly(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $errors = [
            new ValidationError('$.a', 'msg', 'type', null, null),
            new ValidationError('$.b', 'msg', 'required', null, null),
            new ValidationError('$.c', 'msg', 'additionalProperties', null, null),
            new ValidationError('$.d', 'msg', 'format', null, null),
            new ValidationError('$.e', 'msg', 'minLength', null, null),
        ];

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::invalid($errors));

        $persistedDrifts = [];
        $this->entityManager
            ->method('persist')
            ->with(self::callback(function (SchemaDrift $drift) use (&$persistedDrifts): bool {
                $persistedDrifts[] = $drift;
                return true;
            }));

        $this->service->validate($token, 'api.example.com', '/users', 'GET', '{}');

        self::assertCount(5, $persistedDrifts);
        self::assertSame(DriftType::TypeChanged, $persistedDrifts[0]->getDriftType());
        self::assertSame(DriftType::FieldRemoved, $persistedDrifts[1]->getDriftType());
        self::assertSame(DriftType::FieldAdded, $persistedDrifts[2]->getDriftType());
        self::assertSame(DriftType::StructureChanged, $persistedDrifts[3]->getDriftType());
        self::assertSame(DriftType::StructureChanged, $persistedDrifts[4]->getDriftType());
    }

    #[Test]
    public function itNormalizesHttpMethodToUppercase(): void
    {
        $token = $this->createToken(TokenMode::Validating);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->with(
                $token->getId(),
                'api.example.com',
                '/users',
                'GET',
                SchemaType::Response
            )
            ->willReturn(null);

        $this->schemaValidator->expects(self::never())->method('validate');
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'get',
            '{"id": 1}'
        );
    }

    #[Test]
    public function itSkipsRequestBodyValidationWhenDisabled(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $token->setValidateRequestBody(false);

        $responseSchema = $this->createMasterSchema($token, SchemaType::Response);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->with(
                $token->getId(),
                'api.example.com',
                '/users',
                'POST',
                SchemaType::Response
            )
            ->willReturn($responseSchema);

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::valid());

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'POST',
            '{"id": 1}',
            '{"name": "John"}'
        );
    }

    #[Test]
    public function itSkipsRequestBodyValidationWhenNoRequestSchema(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $token->setValidateRequestBody(true);

        $responseSchema = $this->createMasterSchema($token, SchemaType::Response);

        $this->schemaRepository
            ->expects(self::exactly(2))
            ->method('findMasterSchema')
            ->willReturnCallback(function ($tokenId, $host, $path, $method, $schemaType) use ($responseSchema) {
                if ($schemaType === SchemaType::Response) {
                    return $responseSchema;
                }
                return null;
            });

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::valid());

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'POST',
            '{"id": 1}',
            '{"name": "John"}'
        );
    }

    #[Test]
    public function itValidatesRequestBodyWhenEnabled(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $token->setValidateRequestBody(true);

        $responseSchema = $this->createMasterSchema($token, SchemaType::Response);
        $requestSchema = $this->createMasterSchema($token, SchemaType::Request);

        $this->schemaRepository
            ->expects(self::exactly(2))
            ->method('findMasterSchema')
            ->willReturnCallback(function ($tokenId, $host, $path, $method, $schemaType) use ($responseSchema, $requestSchema) {
                return $schemaType === SchemaType::Response ? $responseSchema : $requestSchema;
            });

        $this->schemaValidator
            ->expects(self::exactly(2))
            ->method('validate')
            ->willReturn(ValidationResult::valid());

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'POST',
            '{"id": 1}',
            '{"name": "John"}'
        );
    }

    #[Test]
    public function itRecordsDriftForInvalidRequestBody(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $token->setValidateRequestBody(true);

        $responseSchema = $this->createMasterSchema($token, SchemaType::Response);
        $requestSchema = $this->createMasterSchema($token, SchemaType::Request);

        $this->schemaRepository
            ->expects(self::exactly(2))
            ->method('findMasterSchema')
            ->willReturnCallback(function ($tokenId, $host, $path, $method, $schemaType) use ($responseSchema, $requestSchema) {
                return $schemaType === SchemaType::Response ? $responseSchema : $requestSchema;
            });

        $requestError = new ValidationError(
            path: '$.name',
            message: 'The data must be a string',
            keyword: 'type',
            expected: 'string',
            actual: 'integer',
        );

        $this->schemaValidator
            ->expects(self::exactly(2))
            ->method('validate')
            ->willReturnOnConsecutiveCalls(
                ValidationResult::valid(),
                ValidationResult::invalid([$requestError])
            );

        $persistedDrift = null;
        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (SchemaDrift $drift) use (&$persistedDrift): bool {
                $persistedDrift = $drift;
                return true;
            }));

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'POST',
            '{"id": 1}',
            '{"name": 123}'
        );

        self::assertNotNull($persistedDrift);
        self::assertSame($requestSchema, $persistedDrift->getSchema());
        self::assertSame(DriftType::TypeChanged, $persistedDrift->getDriftType());
        self::assertSame('$.name', $persistedDrift->getPath());
    }

    #[Test]
    public function itRecordsDriftsForBothRequestAndResponseBodies(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $token->setValidateRequestBody(true);

        $responseSchema = $this->createMasterSchema($token, SchemaType::Response);
        $requestSchema = $this->createMasterSchema($token, SchemaType::Request);

        $this->schemaRepository
            ->expects(self::exactly(2))
            ->method('findMasterSchema')
            ->willReturnCallback(function ($tokenId, $host, $path, $method, $schemaType) use ($responseSchema, $requestSchema) {
                return $schemaType === SchemaType::Response ? $responseSchema : $requestSchema;
            });

        $responseError = new ValidationError('$.id', 'Type error', 'type', 'integer', 'string');
        $requestError = new ValidationError('$.name', 'Type error', 'type', 'string', 'integer');

        $this->schemaValidator
            ->expects(self::exactly(2))
            ->method('validate')
            ->willReturnOnConsecutiveCalls(
                ValidationResult::invalid([$responseError]),
                ValidationResult::invalid([$requestError])
            );

        $persistedDrifts = [];
        $this->entityManager
            ->expects(self::exactly(2))
            ->method('persist')
            ->with(self::callback(function (SchemaDrift $drift) use (&$persistedDrifts): bool {
                $persistedDrifts[] = $drift;
                return true;
            }));

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'POST',
            '{"id": "not-an-int"}',
            '{"name": 123}'
        );

        self::assertCount(2, $persistedDrifts);
        self::assertSame($responseSchema, $persistedDrifts[0]->getSchema());
        self::assertSame($requestSchema, $persistedDrifts[1]->getSchema());
    }

    #[Test]
    public function itSkipsRequestBodyValidationWhenRequestBodyIsNull(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $token->setValidateRequestBody(true);

        $responseSchema = $this->createMasterSchema($token, SchemaType::Response);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->with(
                $token->getId(),
                'api.example.com',
                '/users',
                'GET',
                SchemaType::Response
            )
            ->willReturn($responseSchema);

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::valid());

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1}',
            null
        );
    }

    private function createToken(TokenMode $mode): ApiToken
    {
        $token = new ApiToken();
        $reflection = new \ReflectionClass($token);

        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($token, Uuid::v7());

        $token->setName('Test Token')
            ->setTokenHash(hash('sha256', 'test-token'))
            ->setMode($mode);

        return $token;
    }

    private function createMasterSchema(ApiToken $token, SchemaType $schemaType = SchemaType::Response): ApiSchema
    {
        $schema = new ApiSchema();
        $schema->setToken($token)
            ->setTargetHost('api.example.com')
            ->setEndpointPath('/users')
            ->setHttpMethod('GET')
            ->setSchemaType($schemaType)
            ->setJsonSchema([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
                'required' => ['id', 'name'],
                'additionalProperties' => false,
            ])
            ->setIsMaster(true);

        return $schema;
    }

    #[Test]
    public function itDoesNotUpdateRequestLogWhenNoSchemaExists(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $requestLogId = Uuid::v7();
        $requestLog = new RequestLog($requestLogId);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn(null);

        $this->schemaValidator->expects(self::never())->method('validate');

        $this->entityManager
            ->expects(self::once())
            ->method('find')
            ->with(RequestLog::class, self::callback(fn (Uuid $uuid) => $uuid->toRfc4122() === $requestLogId->toRfc4122()))
            ->willReturn($requestLog);

        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1}',
            null,
            $requestLogId->toRfc4122()
        );

        self::assertNull($requestLog->isSchemaValidated());
        self::assertNull($requestLog->isDriftDetected());
        self::assertNull($requestLog->getDrift());
    }

    #[Test]
    public function itUpdatesRequestLogWhenValidationPasses(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);
        $requestLogId = Uuid::v7();
        $requestLog = new RequestLog($requestLogId);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::valid());

        $this->entityManager
            ->expects(self::once())
            ->method('find')
            ->with(RequestLog::class, self::callback(fn (Uuid $uuid) => $uuid->toRfc4122() === $requestLogId->toRfc4122()))
            ->willReturn($requestLog);

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1}',
            null,
            $requestLogId->toRfc4122()
        );

        self::assertTrue($requestLog->isSchemaValidated());
        self::assertFalse($requestLog->isDriftDetected());
        self::assertNull($requestLog->getDrift());
    }

    #[Test]
    public function itUpdatesRequestLogWhenDriftDetected(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);
        $requestLogId = Uuid::v7();
        $requestLog = new RequestLog($requestLogId);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $validationError = new ValidationError(
            path: '$.name',
            message: 'The data must be a string',
            keyword: 'type',
            expected: 'string',
            actual: 'integer',
        );

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::invalid([$validationError]));

        $this->entityManager
            ->expects(self::once())
            ->method('find')
            ->with(RequestLog::class, self::callback(fn (Uuid $uuid) => $uuid->toRfc4122() === $requestLogId->toRfc4122()))
            ->willReturn($requestLog);

        $persistedDrift = null;
        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (SchemaDrift $drift) use (&$persistedDrift, $requestLog): bool {
                $persistedDrift = $drift;
                return $drift->getRequestLog() === $requestLog;
            }));

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"name": 123}',
            null,
            $requestLogId->toRfc4122()
        );

        self::assertTrue($requestLog->isSchemaValidated());
        self::assertTrue($requestLog->isDriftDetected());
        self::assertSame($persistedDrift, $requestLog->getDrift());
    }

    #[Test]
    public function itHandlesNullRequestLogId(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $schema = $this->createMasterSchema($token);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::valid());

        $this->entityManager->expects(self::never())->method('find');
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1}',
            null,
            null
        );
    }

    #[Test]
    public function itDispatchesBodyStorageMessageWhenDriftDetectedAndLogLevelIsDriftOnly(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $token->setLogLevel(LogLevel::DriftOnly);
        $schema = $this->createMasterSchema($token);
        $requestLogId = Uuid::v7();
        $requestLog = new RequestLog($requestLogId);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $validationError = new ValidationError(
            path: '$.name',
            message: 'The data must be a string',
            keyword: 'type',
            expected: 'string',
            actual: 'integer',
        );

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::invalid([$validationError]));

        $this->entityManager
            ->expects(self::once())
            ->method('find')
            ->willReturn($requestLog);

        $dispatchedMessage = null;
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (DriftPayloadMessage $message) use (&$dispatchedMessage, $requestLogId): bool {
                $dispatchedMessage = $message;
                return $message->requestLogId === $requestLogId->toRfc4122()
                    && $message->requestBody === '{"name": "test"}'
                    && $message->responseBody === '{"name": 123}';
            }))
            ->willReturnCallback(fn (object $message) => new Envelope($message));

        $this->service = new SchemaValidationService(
            $this->schemaValidator,
            $this->schemaRepository,
            $this->entityManager,
            $this->driftClassifier,
            $this->messageBus,
            $this->alertDispatcher,
            $this->eventDispatcher,
        );

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"name": 123}',
            '{"name": "test"}',
            $requestLogId->toRfc4122()
        );

        self::assertNotNull($dispatchedMessage);
    }

    #[Test]
    public function itDoesNotDispatchBodyStorageMessageWhenLogLevelIsNotDriftOnly(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $token->setLogLevel(LogLevel::FullAudit);
        $schema = $this->createMasterSchema($token);
        $requestLogId = Uuid::v7();
        $requestLog = new RequestLog($requestLogId);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $validationError = new ValidationError(
            path: '$.name',
            message: 'The data must be a string',
            keyword: 'type',
            expected: 'string',
            actual: 'integer',
        );

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::invalid([$validationError]));

        $this->entityManager
            ->expects(self::once())
            ->method('find')
            ->willReturn($requestLog);

        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus
            ->expects(self::never())
            ->method('dispatch');

        $this->service = new SchemaValidationService(
            $this->schemaValidator,
            $this->schemaRepository,
            $this->entityManager,
            $this->driftClassifier,
            $this->messageBus,
            $this->alertDispatcher,
            $this->eventDispatcher,
        );

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"name": 123}',
            '{"name": "test"}',
            $requestLogId->toRfc4122()
        );
    }

    #[Test]
    public function itDoesNotDispatchBodyStorageMessageWhenNoDriftDetected(): void
    {
        $token = $this->createToken(TokenMode::Validating);
        $token->setLogLevel(LogLevel::DriftOnly);
        $schema = $this->createMasterSchema($token);
        $requestLogId = Uuid::v7();
        $requestLog = new RequestLog($requestLogId);

        $this->schemaRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $this->schemaValidator
            ->expects(self::once())
            ->method('validate')
            ->willReturn(ValidationResult::valid());

        $this->entityManager
            ->expects(self::once())
            ->method('find')
            ->willReturn($requestLog);

        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus
            ->expects(self::never())
            ->method('dispatch');

        $this->service = new SchemaValidationService(
            $this->schemaValidator,
            $this->schemaRepository,
            $this->entityManager,
            $this->driftClassifier,
            $this->messageBus,
            $this->alertDispatcher,
            $this->eventDispatcher,
        );

        $this->service->validate(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1, "name": "John"}',
            null,
            $requestLogId->toRfc4122()
        );
    }
}
