<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\RequestLog;
use App\Entity\SchemaDrift;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SchemaDrift::class)]
final class SchemaDriftTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $drift = new SchemaDrift();

        self::assertInstanceOf(Uuid::class, $drift->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $drift->getCreatedAt());
        self::assertNull($drift->getRequestLog());
        self::assertNull($drift->getExpectedValue());
        self::assertNull($drift->getActualValue());
    }

    #[Test]
    public function settersAndGettersWork(): void
    {
        $drift = new SchemaDrift();
        $schema = new ApiSchema();
        $token = new ApiToken();
        $requestLog = new RequestLog();

        $expectedValue = ['type' => 'string'];
        $actualValue = ['type' => 'integer'];

        $drift->setSchema($schema);
        $drift->setToken($token);
        $drift->setRequestLog($requestLog);
        $drift->setDriftType(DriftType::TypeChanged);
        $drift->setPath('$.data.user.email');
        $drift->setExpectedValue($expectedValue);
        $drift->setActualValue($actualValue);
        $drift->setSeverity(DriftSeverity::Warning);

        self::assertSame($schema, $drift->getSchema());
        self::assertSame($token, $drift->getToken());
        self::assertSame($requestLog, $drift->getRequestLog());
        self::assertSame(DriftType::TypeChanged, $drift->getDriftType());
        self::assertSame('$.data.user.email', $drift->getPath());
        self::assertSame($expectedValue, $drift->getExpectedValue());
        self::assertSame($actualValue, $drift->getActualValue());
        self::assertSame(DriftSeverity::Warning, $drift->getSeverity());
    }

    #[Test]
    public function requestLogCanBeSetToNull(): void
    {
        $drift = new SchemaDrift();
        $requestLog = new RequestLog();

        $drift->setRequestLog($requestLog);
        self::assertSame($requestLog, $drift->getRequestLog());

        $drift->setRequestLog(null);
        self::assertNull($drift->getRequestLog());
    }

    #[Test]
    public function driftTypeCanBeSetToFieldAdded(): void
    {
        $drift = new SchemaDrift();

        $drift->setDriftType(DriftType::FieldAdded);

        self::assertSame(DriftType::FieldAdded, $drift->getDriftType());
    }

    #[Test]
    public function driftTypeCanBeSetToFieldRemoved(): void
    {
        $drift = new SchemaDrift();

        $drift->setDriftType(DriftType::FieldRemoved);

        self::assertSame(DriftType::FieldRemoved, $drift->getDriftType());
    }

    #[Test]
    public function driftTypeCanBeSetToTypeChanged(): void
    {
        $drift = new SchemaDrift();

        $drift->setDriftType(DriftType::TypeChanged);

        self::assertSame(DriftType::TypeChanged, $drift->getDriftType());
    }

    #[Test]
    public function driftTypeCanBeSetToStructureChanged(): void
    {
        $drift = new SchemaDrift();

        $drift->setDriftType(DriftType::StructureChanged);

        self::assertSame(DriftType::StructureChanged, $drift->getDriftType());
    }

    #[Test]
    public function severityCanBeSetToInfo(): void
    {
        $drift = new SchemaDrift();

        $drift->setSeverity(DriftSeverity::Info);

        self::assertSame(DriftSeverity::Info, $drift->getSeverity());
    }

    #[Test]
    public function severityCanBeSetToWarning(): void
    {
        $drift = new SchemaDrift();

        $drift->setSeverity(DriftSeverity::Warning);

        self::assertSame(DriftSeverity::Warning, $drift->getSeverity());
    }

    #[Test]
    public function severityCanBeSetToCritical(): void
    {
        $drift = new SchemaDrift();

        $drift->setSeverity(DriftSeverity::Critical);

        self::assertSame(DriftSeverity::Critical, $drift->getSeverity());
    }

    #[Test]
    public function expectedValueCanBeSetToNull(): void
    {
        $drift = new SchemaDrift();

        $drift->setExpectedValue(['type' => 'string']);
        self::assertSame(['type' => 'string'], $drift->getExpectedValue());

        $drift->setExpectedValue(null);
        self::assertNull($drift->getExpectedValue());
    }

    #[Test]
    public function actualValueCanBeSetToNull(): void
    {
        $drift = new SchemaDrift();

        $drift->setActualValue(['type' => 'integer']);
        self::assertSame(['type' => 'integer'], $drift->getActualValue());

        $drift->setActualValue(null);
        self::assertNull($drift->getActualValue());
    }

    #[Test]
    public function fluentSettersReturnSelf(): void
    {
        $drift = new SchemaDrift();
        $schema = new ApiSchema();
        $token = new ApiToken();
        $requestLog = new RequestLog();

        self::assertSame($drift, $drift->setSchema($schema));
        self::assertSame($drift, $drift->setToken($token));
        self::assertSame($drift, $drift->setRequestLog($requestLog));
        self::assertSame($drift, $drift->setDriftType(DriftType::FieldAdded));
        self::assertSame($drift, $drift->setPath('$.data'));
        self::assertSame($drift, $drift->setExpectedValue([]));
        self::assertSame($drift, $drift->setActualValue([]));
        self::assertSame($drift, $drift->setSeverity(DriftSeverity::Info));
    }
}
