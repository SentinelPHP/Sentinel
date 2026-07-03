<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use SentinelPHP\Dto\Enum\SchemaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ApiSchema::class)]
final class ApiSchemaTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $schema = new ApiSchema();

        self::assertInstanceOf(Uuid::class, $schema->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $schema->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $schema->getUpdatedAt());
        self::assertSame(1, $schema->getVersion());
        self::assertFalse($schema->isMaster());
        self::assertSame([], $schema->getJsonSchema());
        self::assertSame(1, $schema->getSampleCount());
    }

    #[Test]
    public function settersAndGettersWork(): void
    {
        $schema = new ApiSchema();
        $token = new ApiToken();

        $jsonSchema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
            ],
        ];

        $schema->setToken($token);
        $schema->setTargetHost('api.example.com');
        $schema->setEndpointPath('/users/{id}');
        $schema->setHttpMethod('GET');
        $schema->setSchemaType(SchemaType::Response);
        $schema->setJsonSchema($jsonSchema);
        $schema->setVersion(3);
        $schema->setIsMaster(true);

        self::assertSame($token, $schema->getToken());
        self::assertSame('api.example.com', $schema->getTargetHost());
        self::assertSame('/users/{id}', $schema->getEndpointPath());
        self::assertSame('GET', $schema->getHttpMethod());
        self::assertSame(SchemaType::Response, $schema->getSchemaType());
        self::assertSame($jsonSchema, $schema->getJsonSchema());
        self::assertSame(3, $schema->getVersion());
        self::assertTrue($schema->isMaster());
    }

    #[Test]
    public function httpMethodIsNormalizedToUppercase(): void
    {
        $schema = new ApiSchema();

        $schema->setHttpMethod('post');
        self::assertSame('POST', $schema->getHttpMethod());

        $schema->setHttpMethod('Get');
        self::assertSame('GET', $schema->getHttpMethod());
    }

    #[Test]
    public function incrementVersionIncreasesVersionByOne(): void
    {
        $schema = new ApiSchema();

        self::assertSame(1, $schema->getVersion());

        $schema->incrementVersion();
        self::assertSame(2, $schema->getVersion());

        $schema->incrementVersion();
        self::assertSame(3, $schema->getVersion());
    }

    #[Test]
    public function incrementVersionReturnsSelf(): void
    {
        $schema = new ApiSchema();

        $result = $schema->incrementVersion();

        self::assertSame($schema, $result);
    }

    #[Test]
    public function sampleCountCanBeSetAndRetrieved(): void
    {
        $schema = new ApiSchema();

        $schema->setSampleCount(5);

        self::assertSame(5, $schema->getSampleCount());
    }

    #[Test]
    public function incrementSampleCountIncreasesByOne(): void
    {
        $schema = new ApiSchema();

        self::assertSame(1, $schema->getSampleCount());

        $schema->incrementSampleCount();
        self::assertSame(2, $schema->getSampleCount());

        $schema->incrementSampleCount();
        self::assertSame(3, $schema->getSampleCount());
    }

    #[Test]
    public function incrementSampleCountReturnsSelf(): void
    {
        $schema = new ApiSchema();

        $result = $schema->incrementSampleCount();

        self::assertSame($schema, $result);
    }

    #[Test]
    public function isStableReturnsTrueWhenSampleCountMeetsThreshold(): void
    {
        $schema = new ApiSchema();
        $schema->setSampleCount(10);

        self::assertTrue($schema->isStable(10));
        self::assertTrue($schema->isStable(5));
    }

    #[Test]
    public function isStableReturnsFalseWhenSampleCountBelowThreshold(): void
    {
        $schema = new ApiSchema();
        $schema->setSampleCount(5);

        self::assertFalse($schema->isStable(10));
        self::assertFalse($schema->isStable(6));
    }

    #[Test]
    public function updateTimestampUpdatesUpdatedAt(): void
    {
        $schema = new ApiSchema();
        $originalUpdatedAt = $schema->getUpdatedAt();

        usleep(1000);

        $schema->updateTimestamp();

        self::assertGreaterThan($originalUpdatedAt, $schema->getUpdatedAt());
    }

    #[Test]
    public function schemaTypeCanBeSetToRequest(): void
    {
        $schema = new ApiSchema();

        $schema->setSchemaType(SchemaType::Request);

        self::assertSame(SchemaType::Request, $schema->getSchemaType());
    }

    #[Test]
    public function schemaTypeCanBeSetToResponse(): void
    {
        $schema = new ApiSchema();

        $schema->setSchemaType(SchemaType::Response);

        self::assertSame(SchemaType::Response, $schema->getSchemaType());
    }

    #[Test]
    public function fluentSettersReturnSelf(): void
    {
        $schema = new ApiSchema();
        $token = new ApiToken();

        self::assertSame($schema, $schema->setToken($token));
        self::assertSame($schema, $schema->setTargetHost('api.example.com'));
        self::assertSame($schema, $schema->setEndpointPath('/users'));
        self::assertSame($schema, $schema->setHttpMethod('GET'));
        self::assertSame($schema, $schema->setSchemaType(SchemaType::Response));
        self::assertSame($schema, $schema->setJsonSchema([]));
        self::assertSame($schema, $schema->setVersion(1));
        self::assertSame($schema, $schema->setIsMaster(false));
        self::assertSame($schema, $schema->setSampleCount(1));
    }
}
