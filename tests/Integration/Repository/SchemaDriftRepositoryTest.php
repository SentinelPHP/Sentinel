<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use App\Repository\SchemaDriftRepository;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\SchemaDriftFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(SchemaDriftRepository::class)]
final class SchemaDriftRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private SchemaDriftRepository $repository;
    private ApiToken $token;
    private ApiSchema $schema;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var SchemaDriftRepository $repository */
        $repository = self::getContainer()->get(SchemaDriftRepository::class);
        $this->repository = $repository;
        $this->token = ApiTokenFactory::new()->create();
        $this->schema = ApiSchemaFactory::new()->create(['token' => $this->token]);
    }

    #[Test]
    public function findBySchemaIdReturnsEmptyArrayWhenNoDriftsExist(): void
    {
        $result = $this->repository->findBySchemaId($this->schema->getId());

        self::assertSame([], $result);
    }

    #[Test]
    public function findBySchemaIdReturnsDriftsOrderedByCreatedAtDesc(): void
    {
        SchemaDriftFactory::new()->createMany(3, [
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $result = $this->repository->findBySchemaId($this->schema->getId());

        self::assertCount(3, $result);
    }

    #[Test]
    public function findByTokenIdReturnsEmptyArrayWhenNoDriftsExist(): void
    {
        $result = $this->repository->findByTokenId($this->token->getId());

        self::assertSame([], $result);
    }

    #[Test]
    public function findByTokenIdReturnsDriftsForToken(): void
    {
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $otherToken = ApiTokenFactory::new()->create();
        $otherSchema = ApiSchemaFactory::new()->create(['token' => $otherToken]);
        SchemaDriftFactory::new()->create([
            'schema' => $otherSchema,
            'token' => $otherToken,
        ]);

        $result = $this->repository->findByTokenId($this->token->getId());

        self::assertCount(1, $result);
        self::assertEquals($this->token->getId(), $result[0]->getToken()->getId());
    }

    #[Test]
    public function findBySeverityReturnsDriftsWithMatchingSeverity(): void
    {
        SchemaDriftFactory::new()->critical()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);
        SchemaDriftFactory::new()->warning()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $result = $this->repository->findBySeverity(DriftSeverity::Critical);

        self::assertCount(1, $result);
        self::assertSame(DriftSeverity::Critical, $result[0]->getSeverity());
    }

    #[Test]
    public function findRecentReturnsLimitedResults(): void
    {
        SchemaDriftFactory::new()->createMany(10, [
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $result = $this->repository->findRecent(5);

        self::assertCount(5, $result);
    }

    #[Test]
    public function countBySeverityReturnsCorrectCounts(): void
    {
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'severity' => DriftSeverity::Critical,
        ]);
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'severity' => DriftSeverity::Critical,
        ]);
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'severity' => DriftSeverity::Critical,
        ]);
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'severity' => DriftSeverity::Warning,
        ]);
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'severity' => DriftSeverity::Warning,
        ]);
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'severity' => DriftSeverity::Info,
        ]);

        $result = $this->repository->countBySeverity($this->token->getId());

        self::assertSame(3, $result['critical']);
        self::assertSame(2, $result['warning']);
        self::assertSame(1, $result['info']);
    }

    #[Test]
    public function findByTokenIdAndSeverityReturnsFilteredResults(): void
    {
        SchemaDriftFactory::new()->critical()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);
        SchemaDriftFactory::new()->warning()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $result = $this->repository->findByTokenIdAndSeverity(
            $this->token->getId(),
            DriftSeverity::Critical,
        );

        self::assertCount(1, $result);
        self::assertSame(DriftSeverity::Critical, $result[0]->getSeverity());
    }

    #[Test]
    public function findByTokenIdAndDriftTypeReturnsFilteredResults(): void
    {
        SchemaDriftFactory::new()->fieldAdded()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);
        SchemaDriftFactory::new()->fieldRemoved()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $result = $this->repository->findByTokenIdAndDriftType(
            $this->token->getId(),
            DriftType::FieldAdded,
        );

        self::assertCount(1, $result);
        self::assertSame(DriftType::FieldAdded, $result[0]->getDriftType());
    }

    #[Test]
    public function findByDateRangeReturnsResultsWithinRange(): void
    {
        $now = new \DateTimeImmutable();
        $yesterday = $now->modify('-1 day');
        $twoDaysAgo = $now->modify('-2 days');

        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $result = $this->repository->findByDateRange($yesterday, $now);

        self::assertCount(1, $result);
    }

    #[Test]
    public function findByDateRangeRespectsLimit(): void
    {
        SchemaDriftFactory::new()->createMany(10, [
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $now = new \DateTimeImmutable();
        $yesterday = $now->modify('-1 day');

        $result = $this->repository->findByDateRange($yesterday, $now, 3);

        self::assertCount(3, $result);
    }

    #[Test]
    public function findByTokenIdWithFiltersAppliesAllFilters(): void
    {
        SchemaDriftFactory::new()->critical()->fieldAdded()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);
        SchemaDriftFactory::new()->warning()->fieldRemoved()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);
        SchemaDriftFactory::new()->critical()->fieldRemoved()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $result = $this->repository->findByTokenIdWithFilters(
            $this->token->getId(),
            DriftSeverity::Critical,
            DriftType::FieldAdded,
        );

        self::assertCount(1, $result);
        self::assertSame(DriftSeverity::Critical, $result[0]->getSeverity());
        self::assertSame(DriftType::FieldAdded, $result[0]->getDriftType());
    }

    #[Test]
    public function findByTokenIdWithFiltersAppliesPagination(): void
    {
        SchemaDriftFactory::new()->createMany(10, [
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $result = $this->repository->findByTokenIdWithFilters(
            $this->token->getId(),
            limit: 3,
            offset: 2,
        );

        self::assertCount(3, $result);
    }

    #[Test]
    public function findByTokenIdWithFiltersAppliesDateRange(): void
    {
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $now = new \DateTimeImmutable();
        $yesterday = $now->modify('-1 day');
        $tomorrow = $now->modify('+1 day');

        $resultInRange = $this->repository->findByTokenIdWithFilters(
            $this->token->getId(),
            from: $yesterday,
            to: $tomorrow,
        );
        self::assertCount(1, $resultInRange);

        $resultOutOfRange = $this->repository->findByTokenIdWithFilters(
            $this->token->getId(),
            from: $now->modify('-10 days'),
            to: $now->modify('-5 days'),
        );
        self::assertCount(0, $resultOutOfRange);
    }

    #[Test]
    public function countByDriftTypeReturnsCorrectCounts(): void
    {
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'driftType' => DriftType::FieldAdded,
        ]);
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'driftType' => DriftType::FieldAdded,
        ]);
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'driftType' => DriftType::FieldAdded,
        ]);
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'driftType' => DriftType::FieldRemoved,
        ]);
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'driftType' => DriftType::FieldRemoved,
        ]);
        SchemaDriftFactory::new()->create([
            'schema' => $this->schema,
            'token' => $this->token,
            'driftType' => DriftType::TypeChanged,
        ]);

        $result = $this->repository->countByDriftType($this->token->getId());

        self::assertSame(3, $result['field_added']);
        self::assertSame(2, $result['field_removed']);
        self::assertSame(1, $result['type_changed']);
    }

    #[Test]
    public function countByDateRangeReturnsCountsGroupedByDay(): void
    {
        SchemaDriftFactory::new()->createMany(3, [
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $now = new \DateTimeImmutable();
        $yesterday = $now->modify('-1 day');

        $result = $this->repository->countByDateRange(
            $this->token->getId(),
            $yesterday,
            $now,
            'day',
        );

        self::assertNotEmpty($result);
        $today = $now->format('Y-m-d');
        self::assertArrayHasKey($today, $result);
        self::assertSame(3, $result[$today]);
    }

    #[Test]
    public function findLatestBySchemaReturnsNullWhenNoDriftsExist(): void
    {
        $result = $this->repository->findLatestBySchema($this->schema->getId());

        self::assertNull($result);
    }

    #[Test]
    public function findLatestBySchemaReturnsMostRecentDrift(): void
    {
        SchemaDriftFactory::new()->createMany(3, [
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $allDrifts = $this->repository->findBySchemaId($this->schema->getId());
        $result = $this->repository->findLatestBySchema($this->schema->getId());

        self::assertNotNull($result);
        self::assertEquals($allDrifts[0]->getId(), $result->getId());
    }

    #[Test]
    public function countByTokenIdReturnsCorrectCount(): void
    {
        SchemaDriftFactory::new()->createMany(5, [
            'schema' => $this->schema,
            'token' => $this->token,
        ]);

        $otherToken = ApiTokenFactory::new()->create();
        $otherSchema = ApiSchemaFactory::new()->create(['token' => $otherToken]);
        SchemaDriftFactory::new()->createMany(3, [
            'schema' => $otherSchema,
            'token' => $otherToken,
        ]);

        $result = $this->repository->countByTokenId($this->token->getId());

        self::assertSame(5, $result);
    }
}
