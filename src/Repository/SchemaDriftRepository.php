<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchemaDrift;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SchemaDrift>
 */
final class SchemaDriftRepository extends ServiceEntityRepository implements SchemaDriftRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchemaDrift::class);
    }

    /**
     * @return list<SchemaDrift>
     */
    public function findBySchemaId(Uuid $schemaId): array
    {
        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.schema = :schemaId')
            ->setParameter('schemaId', $schemaId, 'uuid')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<SchemaDrift>
     */
    public function findByTokenId(Uuid $tokenId): array
    {
        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.token = :tokenId')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<SchemaDrift>
     */
    public function findBySeverity(DriftSeverity $severity): array
    {
        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.severity = :severity')
            ->setParameter('severity', $severity)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<SchemaDrift>
     */
    public function findRecent(int $limit = 50): array
    {
        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, int>
     */
    public function countBySeverity(Uuid $tokenId): array
    {
        /** @var list<array{severity: DriftSeverity, count: int|string}> $results */
        $results = $this->createQueryBuilder('d')
            ->select('d.severity as severity, COUNT(d.id) as count')
            ->andWhere('d.token = :tokenId')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->groupBy('d.severity')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['severity']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * @return list<SchemaDrift>
     */
    public function findByTokenIdAndSeverity(Uuid $tokenId, DriftSeverity $severity): array
    {
        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.token = :tokenId')
            ->andWhere('d.severity = :severity')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->setParameter('severity', $severity)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<SchemaDrift>
     */
    public function findByTokenIdAndDriftType(Uuid $tokenId, DriftType $driftType): array
    {
        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.token = :tokenId')
            ->andWhere('d.driftType = :driftType')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->setParameter('driftType', $driftType)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<SchemaDrift>
     */
    public function findByDateRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        int $limit = 100,
    ): array {
        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.createdAt >= :from')
            ->andWhere('d.createdAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<SchemaDrift>
     */
    public function findByTokenIdWithFilters(
        Uuid $tokenId,
        ?DriftSeverity $severity = null,
        ?DriftType $driftType = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.token = :tokenId')
            ->setParameter('tokenId', $tokenId, 'uuid');

        if ($severity !== null) {
            $qb->andWhere('d.severity = :severity')
                ->setParameter('severity', $severity);
        }

        if ($driftType !== null) {
            $qb->andWhere('d.driftType = :driftType')
                ->setParameter('driftType', $driftType);
        }

        if ($from !== null) {
            $qb->andWhere('d.createdAt >= :from')
                ->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('d.createdAt <= :to')
                ->setParameter('to', $to);
        }

        /** @var list<SchemaDrift> */
        return $qb->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, int>
     */
    public function countByDriftType(Uuid $tokenId): array
    {
        /** @var list<array{driftType: DriftType, count: int|string}> $results */
        $results = $this->createQueryBuilder('d')
            ->select('d.driftType as driftType, COUNT(d.id) as count')
            ->andWhere('d.token = :tokenId')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->groupBy('d.driftType')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['driftType']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function countByDateRange(
        Uuid $tokenId,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy = 'day',
    ): array {
        $dateFormat = match ($groupBy) {
            'hour' => 'YYYY-MM-DD HH24:00',
            'day' => 'YYYY-MM-DD',
            'month' => 'YYYY-MM',
            default => 'YYYY-MM-DD',
        };

        $conn = $this->getEntityManager()->getConnection();

        $sql = "SELECT TO_CHAR(created_at, :dateFormat) as period, COUNT(id) as count
                FROM schema_drifts
                WHERE token_id = :tokenId
                AND created_at >= :from
                AND created_at <= :to
                GROUP BY period
                ORDER BY period ASC";

        /** @var list<array{period: string, count: int|string}> $results */
        $results = $conn->executeQuery($sql, [
            'dateFormat' => $dateFormat,
            'tokenId' => $tokenId->toRfc4122(),
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['period']] = (int) $row['count'];
        }

        return $counts;
    }

    public function findLatestBySchema(Uuid $schemaId): ?SchemaDrift
    {
        /** @var SchemaDrift|null */
        return $this->createQueryBuilder('d')
            ->andWhere('d.schema = :schemaId')
            ->setParameter('schemaId', $schemaId, 'uuid')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByTokenId(Uuid $tokenId): int
    {
        /** @var int */
        return $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.token = :tokenId')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array{tokenId?: Uuid, tokenIds?: list<Uuid>, severity?: DriftSeverity, driftType?: DriftType, from?: \DateTimeInterface, to?: \DateTimeInterface} $filters
     * @return list<SchemaDrift>
     */
    public function findWithFilters(array $filters, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('d')
            ->join('d.schema', 's')
            ->join('d.token', 't');

        $this->applyFilters($qb, $filters);

        /** @var list<SchemaDrift> */
        return $qb->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{tokenId?: Uuid, tokenIds?: list<Uuid>, severity?: DriftSeverity, driftType?: DriftType, from?: \DateTimeInterface, to?: \DateTimeInterface} $filters
     */
    public function countWithFilters(array $filters): int
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)');

        $this->applyFilters($qb, $filters);

        /** @var int */
        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array{tokenId?: Uuid, tokenIds?: list<Uuid>, severity?: DriftSeverity, driftType?: DriftType, from?: \DateTimeInterface, to?: \DateTimeInterface} $filters
     * @return array<string, int>
     */
    public function countBySeverityWithFilters(array $filters): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.severity as severity, COUNT(d.id) as count')
            ->groupBy('d.severity');

        $this->applyFilters($qb, $filters);

        /** @var list<array{severity: DriftSeverity, count: int|string}> $results */
        $results = $qb->getQuery()->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['severity']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Count drifts by severity since a given date for the specified tokens.
     *
     * @param list<Uuid> $tokenIds
     * @return array<string, int>
     */
    public function countBySeveritySince(\DateTimeInterface $since, array $tokenIds): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        /** @var list<array{severity: DriftSeverity, count: int|string}> $results */
        $results = $this->createQueryBuilder('d')
            ->select('d.severity as severity, COUNT(d.id) as count')
            ->where('d.createdAt >= :since')
            ->andWhere('d.token IN (:tokenIds)')
            ->setParameter('since', $since)
            ->setParameter('tokenIds', $tokenIds)
            ->groupBy('d.severity')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['severity']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Find recent drifts for the specified tokens.
     *
     * @param list<Uuid> $tokenIds
     * @return list<SchemaDrift>
     */
    public function findRecentByTokenIds(array $tokenIds, int $limit = 5): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->join('d.schema', 's')
            ->addSelect('s')
            ->where('d.token IN (:tokenIds)')
            ->setParameter('tokenIds', $tokenIds)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count drifts by severity for a specific host since a given date.
     *
     * @param list<Uuid> $tokenIds
     * @return array{critical: int, warning: int, info: int}
     */
    public function countBySeverityForHost(\DateTimeInterface $since, array $tokenIds, string $host): array
    {
        if (empty($tokenIds)) {
            return ['critical' => 0, 'warning' => 0, 'info' => 0];
        }

        /** @var list<array{severity: DriftSeverity, count: int|string}> $results */
        $results = $this->createQueryBuilder('d')
            ->select('d.severity as severity, COUNT(d.id) as count')
            ->join('d.schema', 's')
            ->where('d.createdAt >= :since')
            ->andWhere('d.token IN (:tokenIds)')
            ->andWhere('s.targetHost = :host')
            ->setParameter('since', $since)
            ->setParameter('tokenIds', $tokenIds)
            ->setParameter('host', $host)
            ->groupBy('d.severity')
            ->getQuery()
            ->getResult();

        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($results as $row) {
            $counts[$row['severity']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Find recent drifts for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return list<SchemaDrift>
     */
    public function findRecentByHost(array $tokenIds, string $host, int $limit = 10): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->join('d.schema', 's')
            ->addSelect('s')
            ->where('d.token IN (:tokenIds)')
            ->andWhere('s.targetHost = :host')
            ->setParameter('tokenIds', $tokenIds)
            ->setParameter('host', $host)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recent drifts for a specific token.
     *
     * @return list<SchemaDrift>
     */
    public function findRecentByToken(Uuid $tokenId, int $limit = 10): array
    {
        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->join('d.schema', 's')
            ->addSelect('s')
            ->where('d.token = :tokenId')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find drifts for a token with pagination.
     *
     * @return list<SchemaDrift>
     */
    public function findByTokenPaginated(Uuid $tokenId, int $limit = 50, int $offset = 0): array
    {
        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->join('d.schema', 's')
            ->addSelect('s')
            ->where('d.token = :tokenId')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find drifts created since a given timestamp.
     *
     * @param list<Uuid> $tokenIds
     * @return list<SchemaDrift>
     */
    public function findRecentSince(\DateTimeInterface $since, array $tokenIds, int $limit = 20): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        /** @var list<SchemaDrift> */
        return $this->createQueryBuilder('d')
            ->join('d.schema', 's')
            ->join('d.token', 't')
            ->addSelect('s', 't')
            ->where('d.createdAt >= :since')
            ->andWhere('d.token IN (:tokenIds)')
            ->setParameter('since', $since)
            ->setParameter('tokenIds', $tokenIds)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{tokenId?: Uuid, tokenIds?: list<Uuid>, severity?: DriftSeverity, driftType?: DriftType, from?: \DateTimeInterface, to?: \DateTimeInterface} $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['tokenId'])) {
            $qb->andWhere('d.token = :tokenId')
                ->setParameter('tokenId', $filters['tokenId'], 'uuid');
        } elseif (isset($filters['tokenIds']) && $filters['tokenIds'] !== []) {
            $qb->andWhere('d.token IN (:tokenIds)')
                ->setParameter('tokenIds', $filters['tokenIds']);
        }

        if (isset($filters['severity'])) {
            $qb->andWhere('d.severity = :severity')
                ->setParameter('severity', $filters['severity']);
        }

        if (isset($filters['driftType'])) {
            $qb->andWhere('d.driftType = :driftType')
                ->setParameter('driftType', $filters['driftType']);
        }

        if (isset($filters['from'])) {
            $qb->andWhere('d.createdAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('d.createdAt <= :to')
                ->setParameter('to', $filters['to']);
        }
    }
}
