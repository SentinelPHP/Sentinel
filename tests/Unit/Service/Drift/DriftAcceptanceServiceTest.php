<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Drift;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\SchemaDrift;
use App\Entity\User;
use App\Service\Drift\DriftAcceptanceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;

#[CoversClass(DriftAcceptanceService::class)]
#[AllowMockObjectsWithoutExpectations]
final class DriftAcceptanceServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private DriftAcceptanceService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new DriftAcceptanceService($this->entityManager);
    }

    /**
     * @param array<string, mixed>|null $actualValue
     */
    private function createDrift(
        DriftType $type = DriftType::FieldAdded,
        string $path = 'properties.newField',
        ?array $actualValue = null,
        bool $alreadyAccepted = false,
    ): SchemaDrift {
        $token = new ApiToken();
        $schema = new ApiSchema();
        $schema->setToken($token);
        $schema->setJsonSchema([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
            ],
        ]);
        $schema->setVersion(1);

        $drift = new SchemaDrift();
        $drift->setToken($token);
        $drift->setSchema($schema);
        $drift->setDriftType($type);
        $drift->setSeverity(DriftSeverity::Warning);
        $drift->setPath($path);
        $drift->setActualValue($actualValue);

        if ($alreadyAccepted) {
            $drift->setAcceptedAt(new \DateTimeImmutable());
            $drift->setAcceptedBy(new User());
        }

        return $drift;
    }

    #[Test]
    public function canAcceptReturnsTrueForUnacceptedDrift(): void
    {
        $drift = $this->createDrift();

        self::assertTrue($this->service->canAccept($drift));
    }

    #[Test]
    public function canAcceptReturnsFalseForAlreadyAcceptedDrift(): void
    {
        $drift = $this->createDrift(alreadyAccepted: true);

        self::assertFalse($this->service->canAccept($drift));
    }

    #[Test]
    public function acceptDriftThrowsExceptionForAlreadyAcceptedDrift(): void
    {
        $drift = $this->createDrift(alreadyAccepted: true);
        $user = new User();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This drift has already been accepted.');

        $this->service->acceptDrift($drift, $user);
    }

    #[Test]
    public function acceptDriftAddsFieldToSchema(): void
    {
        $drift = $this->createDrift(
            type: DriftType::FieldAdded,
            path: 'properties.email',
            actualValue: ['type' => 'string', 'format' => 'email'],
        );
        $user = new User();

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->acceptDrift($drift, $user);

        $schema = $drift->getSchema();
        /** @var array<string, mixed> $jsonSchema */
        $jsonSchema = $schema->getJsonSchema();

        self::assertIsArray($jsonSchema['properties']);
        self::assertArrayHasKey('email', $jsonSchema['properties']);
        self::assertSame(['type' => 'string', 'format' => 'email'], $jsonSchema['properties']['email']);
        self::assertSame(2, $schema->getVersion());
        self::assertNotNull($drift->getAcceptedAt());
        self::assertSame($user, $drift->getAcceptedBy());
    }

    #[Test]
    public function acceptDriftRemovesFieldFromSchema(): void
    {
        $drift = $this->createDrift(
            type: DriftType::FieldRemoved,
            path: 'properties.name',
        );
        $user = new User();

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->acceptDrift($drift, $user);

        $schema = $drift->getSchema();
        /** @var array<string, mixed> $jsonSchema */
        $jsonSchema = $schema->getJsonSchema();

        self::assertIsArray($jsonSchema['properties']);
        self::assertArrayNotHasKey('name', $jsonSchema['properties']);
        self::assertArrayHasKey('id', $jsonSchema['properties']);
    }

    #[Test]
    public function acceptDriftUpdatesFieldTypeInSchema(): void
    {
        $drift = $this->createDrift(
            type: DriftType::TypeChanged,
            path: 'properties.id',
            actualValue: ['type' => 'string'],
        );
        $user = new User();

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->acceptDrift($drift, $user);

        $schema = $drift->getSchema();
        /** @var array<string, mixed> $jsonSchema */
        $jsonSchema = $schema->getJsonSchema();

        self::assertIsArray($jsonSchema['properties']);
        self::assertSame(['type' => 'string'], $jsonSchema['properties']['id']);
    }

    #[Test]
    public function acceptDriftUpdatesStructureInSchema(): void
    {
        $drift = $this->createDrift(
            type: DriftType::StructureChanged,
            path: 'properties.name',
            actualValue: [
                'type' => 'object',
                'properties' => [
                    'first' => ['type' => 'string'],
                    'last' => ['type' => 'string'],
                ],
            ],
        );
        $user = new User();

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->acceptDrift($drift, $user);

        $schema = $drift->getSchema();
        /** @var array<string, mixed> $jsonSchema */
        $jsonSchema = $schema->getJsonSchema();

        self::assertIsArray($jsonSchema['properties']);
        self::assertIsArray($jsonSchema['properties']['name']);
        self::assertSame('object', $jsonSchema['properties']['name']['type']);
        self::assertIsArray($jsonSchema['properties']['name']['properties']);
        self::assertArrayHasKey('first', $jsonSchema['properties']['name']['properties']);
    }

    #[Test]
    public function acceptDriftHandlesNestedPath(): void
    {
        $schema = new ApiSchema();
        $schema->setToken(new ApiToken());
        $schema->setJsonSchema([
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
        ]);
        $schema->setVersion(1);

        $drift = new SchemaDrift();
        $drift->setToken($schema->getToken());
        $drift->setSchema($schema);
        $drift->setDriftType(DriftType::FieldAdded);
        $drift->setSeverity(DriftSeverity::Info);
        $drift->setPath('properties.user.properties.email');
        $drift->setActualValue(['type' => 'string']);

        $user = new User();

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->acceptDrift($drift, $user);

        /** @var array<string, mixed> $jsonSchema */
        $jsonSchema = $schema->getJsonSchema();

        self::assertIsArray($jsonSchema['properties']);
        self::assertIsArray($jsonSchema['properties']['user']);
        self::assertIsArray($jsonSchema['properties']['user']['properties']);
        self::assertArrayHasKey('email', $jsonSchema['properties']['user']['properties']);
    }

    #[Test]
    public function acceptDriftIncrementsSchemaVersion(): void
    {
        $drift = $this->createDrift();
        $user = new User();

        $initialVersion = $drift->getSchema()->getVersion();

        $this->service->acceptDrift($drift, $user);

        self::assertSame($initialVersion + 1, $drift->getSchema()->getVersion());
    }

    #[Test]
    public function acceptDriftSetsAcceptedAtTimestamp(): void
    {
        $drift = $this->createDrift();
        $user = new User();

        $before = new \DateTimeImmutable();
        $this->service->acceptDrift($drift, $user);
        $after = new \DateTimeImmutable();

        self::assertNotNull($drift->getAcceptedAt());
        self::assertGreaterThanOrEqual($before, $drift->getAcceptedAt());
        self::assertLessThanOrEqual($after, $drift->getAcceptedAt());
    }
}
