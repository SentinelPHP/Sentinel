<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DriftPayload;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DriftPayload>
 */
final class DriftPayloadRepository extends ServiceEntityRepository implements DriftPayloadRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DriftPayload::class);
    }

    public function findByRequestLog(Uuid $requestLogId): ?DriftPayload
    {
        /** @var DriftPayload|null */
        return $this->createQueryBuilder('dp')
            ->andWhere('dp.requestLog = :requestLogId')
            ->setParameter('requestLogId', $requestLogId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countOlderThan(\DateTimeImmutable $cutoff): int
    {
        /** @var int */
        return $this->createQueryBuilder('dp')
            ->select('COUNT(dp.id)')
            ->andWhere('dp.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff, int $batchSize): int
    {
        $totalDeleted = 0;

        do {
            $ids = $this->createQueryBuilder('dp')
                ->select('dp.id')
                ->andWhere('dp.createdAt < :cutoff')
                ->setParameter('cutoff', $cutoff)
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getSingleColumnResult();

            if (count($ids) === 0) {
                break;
            }

            /** @var int $deleted */
            $deleted = $this->createQueryBuilder('dp')
                ->delete()
                ->andWhere('dp.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->execute();

            $totalDeleted += $deleted;
            $this->getEntityManager()->clear();
        } while (count($ids) === $batchSize);

        return $totalDeleted;
    }
}
