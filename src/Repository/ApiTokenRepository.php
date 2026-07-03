<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use App\Enum\TokenMode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository implements ApiTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findByTokenHash(string $tokenHash): ?ApiToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function findActiveByTokenHash(string $tokenHash): ?ApiToken
    {
        return $this->findOneBy([
            'tokenHash' => $tokenHash,
            'isActive' => true,
        ]);
    }

    /**
     * Count tokens by active status for the given token IDs.
     *
     * @param list<Uuid> $tokenIds
     * @return array{total: int, active: int}
     */
    public function countByActiveStatus(array $tokenIds): array
    {
        if (empty($tokenIds)) {
            return ['total' => 0, 'active' => 0];
        }

        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id) as total, SUM(CASE WHEN t.isActive = true THEN 1 ELSE 0 END) as active')
            ->where('t.id IN (:tokenIds)')
            ->setParameter('tokenIds', $tokenIds);

        /** @var array{total: int|string, active: int|string|null} $result */
        $result = $qb->getQuery()->getSingleResult();

        return [
            'total' => (int) $result['total'],
            'active' => (int) ($result['active'] ?? 0),
        ];
    }

    /**
     * Find tokens with filters and pagination.
     *
     * @param list<Uuid> $tokenIds
     * @param array{search?: string, mode?: string, isActive?: bool} $filters
     * @return list<ApiToken>
     */
    public function findWithFilters(array $tokenIds, array $filters, int $limit = 25, int $offset = 0): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('t')
            ->where('t.id IN (:tokenIds)')
            ->setParameter('tokenIds', $tokenIds);

        $this->applyFilters($qb, $filters);

        /** @var list<ApiToken> */
        return $qb->orderBy('t.name', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count tokens with filters.
     *
     * @param list<Uuid> $tokenIds
     * @param array{search?: string, mode?: string, isActive?: bool} $filters
     */
    public function countWithFilters(array $tokenIds, array $filters): int
    {
        if (empty($tokenIds)) {
            return 0;
        }

        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.id IN (:tokenIds)')
            ->setParameter('tokenIds', $tokenIds);

        $this->applyFilters($qb, $filters);

        /** @var int */
        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get token statistics (request count, drift count, last activity).
     *
     * @return array{requestCount: int, driftCount: int, lastActivity: ?\DateTimeImmutable}
     */
    public function getTokenStats(Uuid $tokenId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "SELECT 
                    (SELECT COUNT(id) FROM request_logs WHERE token_id = :tokenId) as request_count,
                    (SELECT COUNT(id) FROM schema_drifts WHERE token_id = :tokenId) as drift_count,
                    (SELECT MAX(created_at) FROM request_logs WHERE token_id = :tokenId) as last_activity";

        /** @var array{request_count: int|string, drift_count: int|string, last_activity: string|null}|false $result */
        $result = $conn->executeQuery($sql, [
            'tokenId' => $tokenId->toRfc4122(),
        ])->fetchAssociative();

        if ($result === false) {
            return ['requestCount' => 0, 'driftCount' => 0, 'lastActivity' => null];
        }

        return [
            'requestCount' => (int) $result['request_count'],
            'driftCount' => (int) $result['drift_count'],
            'lastActivity' => $result['last_activity'] !== null
                ? new \DateTimeImmutable($result['last_activity'])
                : null,
        ];
    }

    /**
     * @param array{search?: string, mode?: string, isActive?: bool} $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['search']) && $filters['search'] !== '') {
            $qb->andWhere('LOWER(t.name) LIKE LOWER(:search)')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['mode']) && $filters['mode'] !== '') {
            $mode = TokenMode::tryFrom($filters['mode']);
            if ($mode !== null) {
                $qb->andWhere('t.mode = :mode')
                    ->setParameter('mode', $mode);
            }
        }

        if (isset($filters['isActive'])) {
            $qb->andWhere('t.isActive = :isActive')
                ->setParameter('isActive', $filters['isActive']);
        }
    }
}
