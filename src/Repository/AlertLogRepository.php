<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AlertLog;
use App\Enum\AlertChannelType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AlertLog>
 */
final class AlertLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertLog::class);
    }

    /**
     * Find logs with filters for dashboard listing.
     *
     * @param array{channelType?: string, status?: string, configId?: string, dateFrom?: \DateTimeImmutable, dateTo?: \DateTimeImmutable} $filters
     * @return list<AlertLog>
     */
    public function findWithFilters(array $filters, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.alertConfiguration', 'c')
            ->leftJoin('l.drift', 'd')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $this->applyFilters($qb, $filters);

        /** @var list<AlertLog> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Count logs with filters.
     *
     * @param array{channelType?: string, status?: string, configId?: string, dateFrom?: \DateTimeImmutable, dateTo?: \DateTimeImmutable} $filters
     */
    public function countWithFilters(array $filters): int
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)');

        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Find recent logs for a specific configuration.
     *
     * @return list<AlertLog>
     */
    public function findRecentByConfiguration(Uuid $configId, int $limit = 10): array
    {
        /** @var list<AlertLog> */
        return $this->createQueryBuilder('l')
            ->andWhere('l.alertConfiguration = :configId')
            ->setParameter('configId', $configId, 'uuid')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recent logs for a specific drift.
     *
     * @return list<AlertLog>
     */
    public function findByDrift(Uuid $driftId): array
    {
        /** @var list<AlertLog> */
        return $this->createQueryBuilder('l')
            ->andWhere('l.drift = :driftId')
            ->setParameter('driftId', $driftId, 'uuid')
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count logs by status for statistics.
     *
     * @return array{success: int, failure: int}
     */
    public function countByStatus(): array
    {
        /** @var list<array{status: string, count: int|string}> $results */
        $results = $this->createQueryBuilder('l')
            ->select('l.status as status, COUNT(l.id) as count')
            ->groupBy('l.status')
            ->getQuery()
            ->getResult();

        $counts = ['success' => 0, 'failure' => 0];
        foreach ($results as $row) {
            if ($row['status'] === 'success' || $row['status'] === 'failure') {
                $counts[$row['status']] = (int) $row['count'];
            }
        }

        return $counts;
    }

    /**
     * Delete logs older than the specified date.
     */
    public function deleteOlderThan(\DateTimeImmutable $date): int
    {
        /** @var int|string $result */
        $result = $this->createQueryBuilder('l')
            ->delete()
            ->andWhere('l.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();

        return (int) $result;
    }

    /**
     * @param array{channelType?: string, status?: string, configId?: string, dateFrom?: \DateTimeImmutable, dateTo?: \DateTimeImmutable} $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['channelType'])) {
            $channelType = AlertChannelType::tryFrom($filters['channelType']);
            if ($channelType !== null) {
                $qb->andWhere('l.channelType = :channelType')
                    ->setParameter('channelType', $channelType);
            }
        }

        if (isset($filters['status'])) {
            $qb->andWhere('l.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['configId'])) {
            $qb->andWhere('l.alertConfiguration = :configId')
                ->setParameter('configId', Uuid::fromString($filters['configId']), 'uuid');
        }

        if (isset($filters['dateFrom'])) {
            $qb->andWhere('l.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $filters['dateFrom']);
        }

        if (isset($filters['dateTo'])) {
            $qb->andWhere('l.createdAt <= :dateTo')
                ->setParameter('dateTo', $filters['dateTo']);
        }
    }
}
