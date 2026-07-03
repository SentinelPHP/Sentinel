<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\SchemaDriftFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ListDriftCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('sentinel:drift:list');
        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function listsAllDrifts(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Test Token']);
        $schema = ApiSchemaFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
        ]);
        SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Warning,
            'driftType' => DriftType::FieldAdded,
            'path' => '$.data.newField',
        ]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('field_added', $output);
        self::assertStringContainsString('$.data.newField', $output);
        self::assertStringContainsString('api.example.com/users', $output);
        self::assertStringContainsString('Test Token', $output);
        self::assertStringContainsString('Total: 1 drift(s)', $output);
    }

    #[Test]
    public function showsInfoWhenNoDriftsFound(): void
    {
        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('No drifts found', $output);
    }

    #[Test]
    public function showsSeverityDistribution(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        SchemaDriftFactory::createMany(2, [
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Critical,
        ]);
        SchemaDriftFactory::createMany(3, [
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Warning,
        ]);
        SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Info,
        ]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Severity Distribution', $output);
        self::assertStringContainsString('Critical: 2', $output);
        self::assertStringContainsString('Warning: 3', $output);
        self::assertStringContainsString('Info: 1', $output);
        self::assertStringContainsString('Total: 6', $output);
    }

    #[Test]
    public function filtersDriftsByTokenName(): void
    {
        $token1 = ApiTokenFactory::createOne(['name' => 'Token Alpha']);
        $token2 = ApiTokenFactory::createOne(['name' => 'Token Beta']);
        $schema1 = ApiSchemaFactory::createOne(['token' => $token1, 'targetHost' => 'api.alpha.com']);
        $schema2 = ApiSchemaFactory::createOne(['token' => $token2, 'targetHost' => 'api.beta.com']);

        SchemaDriftFactory::createOne(['token' => $token1, 'schema' => $schema1]);
        SchemaDriftFactory::createOne(['token' => $token2, 'schema' => $schema2]);

        $this->commandTester->execute(['--token' => 'Token Alpha']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('api.alpha.com', $output);
        self::assertStringNotContainsString('api.beta.com', $output);
        self::assertStringContainsString('Total: 1 drift(s)', $output);
    }

    #[Test]
    public function filtersDriftsByTokenUuid(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'UUID Token']);
        $schema = ApiSchemaFactory::createOne(['token' => $token, 'targetHost' => 'api.uuid.com']);
        SchemaDriftFactory::createOne(['token' => $token, 'schema' => $schema]);

        $otherToken = ApiTokenFactory::createOne();
        $otherSchema = ApiSchemaFactory::createOne(['token' => $otherToken, 'targetHost' => 'api.other.com']);
        SchemaDriftFactory::createOne(['token' => $otherToken, 'schema' => $otherSchema]);

        $this->commandTester->execute(['--token' => $token->getId()->toRfc4122()]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('api.uuid.com', $output);
        self::assertStringNotContainsString('api.other.com', $output);
    }

    #[Test]
    public function filtersDriftsBySeverity(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Critical,
            'path' => '$.critical.path',
        ]);
        SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Info,
            'path' => '$.info.path',
        ]);

        $this->commandTester->execute(['--severity' => 'critical']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('$.critical.path', $output);
        self::assertStringNotContainsString('$.info.path', $output);
    }

    #[Test]
    public function filtersDriftsByDriftType(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'driftType' => DriftType::FieldRemoved,
            'path' => '$.removed.field',
        ]);
        SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'driftType' => DriftType::TypeChanged,
            'path' => '$.changed.type',
        ]);

        $this->commandTester->execute(['--drift-type' => 'field_removed']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('$.removed.field', $output);
        self::assertStringNotContainsString('$.changed.type', $output);
    }

    #[Test]
    public function filtersDriftsByDateRange(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        // Create drifts - we'll filter by today's date range
        // Old drift created "yesterday" via direct SQL update
        $oldDrift = SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'path' => '$.old.drift',
        ]);

        $newDrift = SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'path' => '$.new.drift',
        ]);

        // Update old drift's created_at via entity manager
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $conn = $em->getConnection();
        $conn->executeStatement(
            'UPDATE schema_drifts SET created_at = :date WHERE id = :id',
            [
                'date' => '2024-01-01 10:00:00',
                'id' => $oldDrift->getId()->toRfc4122(),
            ]
        );
        $conn->executeStatement(
            'UPDATE schema_drifts SET created_at = :date WHERE id = :id',
            [
                'date' => '2024-06-15 10:00:00',
                'id' => $newDrift->getId()->toRfc4122(),
            ]
        );

        $this->commandTester->execute([
            '--from' => '2024-06-01',
            '--to' => '2024-06-30',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('$.new.drift', $output);
        self::assertStringNotContainsString('$.old.drift', $output);
    }

    #[Test]
    public function respectsLimitOption(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        SchemaDriftFactory::createMany(5, ['token' => $token, 'schema' => $schema]);

        $this->commandTester->execute(['--limit' => '2']);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Showing 2 of 5 total drifts', $output);
    }

    #[Test]
    public function combinesMultipleFilters(): void
    {
        $token = ApiTokenFactory::createOne(['name' => 'Multi Filter Token']);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Critical,
            'driftType' => DriftType::FieldRemoved,
            'path' => '$.match',
        ]);
        SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Info,
            'driftType' => DriftType::FieldRemoved,
            'path' => '$.wrong.severity',
        ]);
        SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Critical,
            'driftType' => DriftType::FieldAdded,
            'path' => '$.wrong.type',
        ]);

        $this->commandTester->execute([
            '--token' => 'Multi Filter Token',
            '--severity' => 'critical',
            '--drift-type' => 'field_removed',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Total: 1 drift(s)', $output);
        self::assertStringContainsString('$.match', $output);
        self::assertStringNotContainsString('$.wrong', $output);
    }

    #[Test]
    public function failsWithNonExistentToken(): void
    {
        $this->commandTester->execute(['--token' => 'Non Existent Token']);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Token not found', $output);
    }

    #[Test]
    public function failsWithInvalidSeverity(): void
    {
        $this->commandTester->execute(['--severity' => 'invalid']);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Invalid severity', $output);
        self::assertStringContainsString('info, warning, critical', $output);
    }

    #[Test]
    public function failsWithInvalidDriftType(): void
    {
        $this->commandTester->execute(['--drift-type' => 'invalid']);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Invalid drift type', $output);
        self::assertStringContainsString('field_added', $output);
    }

    #[Test]
    public function failsWithInvalidFromDate(): void
    {
        $this->commandTester->execute(['--from' => 'not-a-date']);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Invalid from date', $output);
    }

    #[Test]
    public function failsWithInvalidToDate(): void
    {
        $this->commandTester->execute(['--to' => 'not-a-date']);

        self::assertSame(1, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Invalid to date', $output);
    }

    #[Test]
    public function truncatesLongPaths(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'path' => '$.very.long.path.that.exceeds.the.maximum.display.width.for.the.table',
        ]);

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('...', $output);
    }
}
