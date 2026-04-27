<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Enum\TokenMode;
use App\Repository\ApiSchemaRepository;
use App\Service\SchemaLearningService;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(SchemaLearningService::class)]
final class SchemaLearningServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private SchemaLearningService $service;
    private ApiSchemaRepository $schemaRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var SchemaLearningService $service */
        $service = $container->get(SchemaLearningService::class);
        $this->service = $service;

        /** @var ApiSchemaRepository $repository */
        $repository = $container->get(ApiSchemaRepository::class);
        $this->schemaRepository = $repository;
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
    public function itSkipsLearningWhenTokenIsNotInLearningMode(): void
    {
        $token = $this->createToken(TokenMode::Passive);

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1, "name": "John"}'
        );

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertCount(0, $schemas);
    }

    #[Test]
    public function itSkipsLearningWhenTokenIsInValidatingMode(): void
    {
        $token = $this->createToken(TokenMode::Validating);

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1, "name": "John"}'
        );

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertCount(0, $schemas);
    }

    #[Test]
    public function itSkipsLearningWhenResponseBodyIsEmpty(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            ''
        );

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertCount(0, $schemas);
    }

    #[Test]
    public function itSkipsLearningWhenResponseBodyIsInvalidJson(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            'not valid json'
        );

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertCount(0, $schemas);
    }

    #[Test]
    public function itSkipsLearningWhenResponseBodyIsNotArrayOrObject(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '"just a string"'
        );

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertCount(0, $schemas);
    }

    #[Test]
    public function itCreatesNewSchemaWhenNoExistingSchemaExists(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1, "name": "John"}'
        );

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertCount(1, $schemas);

        $schema = $schemas[0];
        self::assertSame('api.example.com', $schema->getTargetHost());
        self::assertSame('/users', $schema->getEndpointPath());
        self::assertSame('GET', $schema->getHttpMethod());
        self::assertSame(SchemaType::Response, $schema->getSchemaType());
        self::assertSame(1, $schema->getVersion());
        self::assertFalse($schema->isMaster());
        self::assertSame(1, $schema->getSampleCount());

        $jsonSchema = $schema->getJsonSchema();
        self::assertArrayHasKey('$schema', $jsonSchema);
        self::assertArrayHasKey('type', $jsonSchema);
        self::assertSame('object', $jsonSchema['type']);
        self::assertArrayHasKey('properties', $jsonSchema);
    }

    #[Test]
    public function itUpdatesExistingSchemaWhenOneExists(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        ApiSchemaFactory::new()->create([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'jsonSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            'version' => 1,
            'isMaster' => false,
            'sampleCount' => 1,
        ]);

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1, "name": "John", "email": "john@example.com"}'
        );

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertCount(1, $schemas);
        self::assertSame(2, $schemas[0]->getVersion());
        self::assertSame(2, $schemas[0]->getSampleCount());
        self::assertArrayHasKey('properties', $schemas[0]->getJsonSchema());
    }

    #[Test]
    public function itIncrementsSampleCountOnEachLearnCall(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1}'
        );

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 2, "name": "Jane"}'
        );

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 3, "name": "Bob", "email": "bob@example.com"}'
        );

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertCount(1, $schemas);
        self::assertSame(3, $schemas[0]->getSampleCount());
        self::assertSame(3, $schemas[0]->getVersion());
    }

    #[Test]
    public function itNormalizesHttpMethodToUppercase(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'post',
            '{"success": true}'
        );

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'POST',
            SchemaType::Response
        );

        self::assertCount(1, $schemas);
        self::assertSame('POST', $schemas[0]->getHttpMethod());
    }

    #[Test]
    public function itHandlesArrayResponseBodies(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '[{"id": 1}, {"id": 2}]'
        );

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertCount(1, $schemas);

        $jsonSchema = $schemas[0]->getJsonSchema();
        self::assertSame('array', $jsonSchema['type']);
        self::assertArrayHasKey('items', $jsonSchema);
    }

    #[Test]
    public function itDoesNotUpdateMasterSchemas(): void
    {
        $token = $this->createToken(TokenMode::Learning);

        ApiSchemaFactory::new()->create([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
            'schemaType' => SchemaType::Response,
            'jsonSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            'version' => 1,
            'isMaster' => true,
        ]);

        $this->service->learn(
            $token,
            'api.example.com',
            '/users',
            'GET',
            '{"id": 1, "name": "John"}'
        );

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertCount(2, $schemas);

        $masterSchema = array_filter($schemas, fn (ApiSchema $s) => $s->isMaster());
        self::assertCount(1, $masterSchema);
        self::assertSame(1, array_values($masterSchema)[0]->getVersion());
    }

    #[Test]
    public function itAutoPromotesSchemaToMasterWhenThresholdIsReached(): void
    {
        $token = $this->createToken(TokenMode::Learning, learningThreshold: 3);

        $this->service->learn($token, 'api.example.com', '/users', 'GET', '{"id": 1}');
        $this->service->learn($token, 'api.example.com', '/users', 'GET', '{"id": 2}');

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );
        self::assertCount(1, $schemas);
        self::assertFalse($schemas[0]->isMaster(), 'Schema should not be master before threshold');
        self::assertSame(2, $schemas[0]->getSampleCount());

        $this->service->learn($token, 'api.example.com', '/users', 'GET', '{"id": 3}');

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );
        self::assertCount(1, $schemas);
        self::assertTrue($schemas[0]->isMaster(), 'Schema should be promoted to master after threshold');
        self::assertSame(3, $schemas[0]->getSampleCount());
    }

    #[Test]
    public function itDoesNotAutoPromoteWhenThresholdIsNull(): void
    {
        $token = $this->createToken(TokenMode::Learning, learningThreshold: null);

        for ($i = 0; $i < 10; $i++) {
            $this->service->learn($token, 'api.example.com', '/users', 'GET', '{"id": ' . $i . '}');
        }

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );
        self::assertCount(1, $schemas);
        self::assertFalse($schemas[0]->isMaster(), 'Schema should not be auto-promoted when threshold is null');
    }

    #[Test]
    public function itAutoSwitchesToValidatingModeWhenEnabled(): void
    {
        $token = $this->createToken(
            TokenMode::Learning,
            learningThreshold: 2,
            autoSwitchToValidating: true
        );

        self::assertSame(TokenMode::Learning, $token->getMode());

        $this->service->learn($token, 'api.example.com', '/users', 'GET', '{"id": 1}');
        self::assertSame(TokenMode::Learning, $token->getMode());

        $this->service->learn($token, 'api.example.com', '/users', 'GET', '{"id": 2}');
        self::assertSame(TokenMode::Validating, $token->getMode(), 'Token should switch to validating mode');
    }

    #[Test]
    public function itDoesNotSwitchModeWhenAutoSwitchIsDisabled(): void
    {
        $token = $this->createToken(
            TokenMode::Learning,
            learningThreshold: 2,
            autoSwitchToValidating: false
        );

        $this->service->learn($token, 'api.example.com', '/users', 'GET', '{"id": 1}');
        $this->service->learn($token, 'api.example.com', '/users', 'GET', '{"id": 2}');

        $schemas = $this->schemaRepository->findAllVersions(
            $token->getId(),
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );
        self::assertTrue($schemas[0]->isMaster(), 'Schema should be promoted to master');
        self::assertSame(TokenMode::Learning, $token->getMode(), 'Token should remain in learning mode');
    }

    #[Test]
    public function itDoesNotRePromoteAlreadyMasterSchema(): void
    {
        $token = $this->createToken(
            TokenMode::Learning,
            learningThreshold: 2,
            autoSwitchToValidating: true
        );

        $this->service->learn($token, 'api.example.com', '/users', 'GET', '{"id": 1}');
        $this->service->learn($token, 'api.example.com', '/users', 'GET', '{"id": 2}');

        self::assertSame(TokenMode::Validating, $token->getMode());

        $token->setMode(TokenMode::Learning);

        $this->service->learn($token, 'api.example.com', '/users', 'GET', '{"id": 3}');

        self::assertSame(TokenMode::Learning, $token->getMode(), 'Token should not switch again for already-master schema');
    }
}
