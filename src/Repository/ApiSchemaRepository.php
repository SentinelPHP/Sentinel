<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiSchema;
use SentinelPHP\Dto\Enum\SchemaType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ApiSchema>
 */
final class ApiSchemaRepository extends ServiceEntityRepository implements ApiSchemaRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiSchema::class);
    }

    public function findMasterSchema(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): ?ApiSchema {
        $masters = $this->findMasterSchemas($tokenId, $targetHost, $path, $method, $type);

        return $masters[0] ?? null;
    }

    /**
     * Find all master schemas for an endpoint (should normally be 0 or 1, but handles data integrity issues).
     *
     * @return list<ApiSchema>
     */
    public function findMasterSchemas(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): array {
        /** @var list<ApiSchema> */
        return $this->createQueryBuilder('s')
            ->andWhere('s.token = :tokenId')
            ->andWhere('s.targetHost = :targetHost')
            ->andWhere('s.endpointPath = :path')
            ->andWhere('s.httpMethod = :method')
            ->andWhere('s.schemaType = :type')
            ->andWhere('s.isMaster = :isMaster')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->setParameter('targetHost', $targetHost)
            ->setParameter('path', $path)
            ->setParameter('method', strtoupper($method))
            ->setParameter('type', $type)
            ->setParameter('isMaster', true)
            ->orderBy('s.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ApiSchema>
     */
    public function findAllVersions(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): array {
        /** @var list<ApiSchema> */
        return $this->createQueryBuilder('s')
            ->andWhere('s.token = :tokenId')
            ->andWhere('s.targetHost = :targetHost')
            ->andWhere('s.endpointPath = :path')
            ->andWhere('s.httpMethod = :method')
            ->andWhere('s.schemaType = :type')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->setParameter('targetHost', $targetHost)
            ->setParameter('path', $path)
            ->setParameter('method', strtoupper($method))
            ->setParameter('type', $type)
            ->orderBy('s.version', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find the latest learned (non-master) schema for an endpoint.
     */
    public function findLatestLearned(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): ?ApiSchema {
        /** @var ApiSchema|null */
        return $this->createQueryBuilder('s')
            ->andWhere('s.token = :tokenId')
            ->andWhere('s.targetHost = :targetHost')
            ->andWhere('s.endpointPath = :path')
            ->andWhere('s.httpMethod = :method')
            ->andWhere('s.schemaType = :type')
            ->andWhere('s.isMaster = :isMaster')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->setParameter('targetHost', $targetHost)
            ->setParameter('path', $path)
            ->setParameter('method', strtoupper($method))
            ->setParameter('type', $type)
            ->setParameter('isMaster', false)
            ->orderBy('s.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function invalidateMasterSchema(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): void {
        // No-op for non-cached repository
    }

    /**
     * @param array{tokenId?: Uuid, targetHost?: string, endpointPath?: string, masterOnly?: bool} $filters
     * @return list<ApiSchema>
     */
    public function findWithFilters(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.token', 't')
            ->addSelect('t')
            ->orderBy('s.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (isset($filters['tokenId'])) {
            $qb->andWhere('s.token = :tokenId')
                ->setParameter('tokenId', $filters['tokenId'], 'uuid');
        }

        if (isset($filters['targetHost'])) {
            $qb->andWhere('s.targetHost LIKE :targetHost')
                ->setParameter('targetHost', '%' . $filters['targetHost'] . '%');
        }

        if (isset($filters['endpointPath'])) {
            $qb->andWhere('s.endpointPath LIKE :endpointPath')
                ->setParameter('endpointPath', '%' . $filters['endpointPath'] . '%');
        }

        if (isset($filters['masterOnly']) && $filters['masterOnly']) {
            $qb->andWhere('s.isMaster = :isMaster')
                ->setParameter('isMaster', true);
        }

        /** @var list<ApiSchema> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Count total schemas matching filters.
     *
     * @param array{tokenId?: Uuid, targetHost?: string, endpointPath?: string, masterOnly?: bool} $filters
     */
    public function countWithFilters(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)');

        if (isset($filters['tokenId'])) {
            $qb->andWhere('s.token = :tokenId')
                ->setParameter('tokenId', $filters['tokenId'], 'uuid');
        }

        if (isset($filters['targetHost'])) {
            $qb->andWhere('s.targetHost LIKE :targetHost')
                ->setParameter('targetHost', '%' . $filters['targetHost'] . '%');
        }

        if (isset($filters['endpointPath'])) {
            $qb->andWhere('s.endpointPath LIKE :endpointPath')
                ->setParameter('endpointPath', '%' . $filters['endpointPath'] . '%');
        }

        if (isset($filters['masterOnly']) && $filters['masterOnly']) {
            $qb->andWhere('s.isMaster = :isMaster')
                ->setParameter('isMaster', true);
        }

        /** @var int */
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Find schemas filtered by accessible token IDs with additional filters.
     *
     * @param list<Uuid> $tokenIds
     * @param array{tokenIds?: list<Uuid>, tokenId?: Uuid, targetHost?: string, endpointPath?: string, httpMethod?: string, schemaType?: SchemaType, masterOnly?: bool} $filters
     * @return list<ApiSchema>
     */
    public function findByAccessibleTokens(array $tokenIds, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        if ($tokenIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.token', 't')
            ->addSelect('t')
            ->andWhere('s.token IN (:tokenIds)')
            ->setParameter('tokenIds', $tokenIds)
            ->orderBy('s.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $this->applyFilters($qb, $filters);

        /** @var list<ApiSchema> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Count schemas filtered by accessible token IDs with additional filters.
     *
     * @param list<Uuid> $tokenIds
     * @param array{tokenIds?: list<Uuid>, tokenId?: Uuid, targetHost?: string, endpointPath?: string, httpMethod?: string, schemaType?: SchemaType, masterOnly?: bool} $filters
     */
    public function countByAccessibleTokens(array $tokenIds, array $filters = []): int
    {
        if ($tokenIds === []) {
            return 0;
        }

        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.token IN (:tokenIds)')
            ->setParameter('tokenIds', $tokenIds);

        $this->applyFilters($qb, $filters);

        /** @var int */
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Apply common filters to a query builder.
     *
     * @param array{tokenIds?: list<Uuid>, tokenId?: Uuid, targetHost?: string, endpointPath?: string, httpMethod?: string, schemaType?: SchemaType, masterOnly?: bool} $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['tokenId'])) {
            $qb->andWhere('s.token = :tokenId')
                ->setParameter('tokenId', $filters['tokenId'], 'uuid');
        }

        if (isset($filters['targetHost']) && $filters['targetHost'] !== '') {
            $qb->andWhere('s.targetHost LIKE :targetHost')
                ->setParameter('targetHost', '%' . $filters['targetHost'] . '%');
        }

        if (isset($filters['endpointPath']) && $filters['endpointPath'] !== '') {
            $qb->andWhere('s.endpointPath LIKE :endpointPath')
                ->setParameter('endpointPath', '%' . $filters['endpointPath'] . '%');
        }

        if (isset($filters['httpMethod']) && $filters['httpMethod'] !== '') {
            $qb->andWhere('s.httpMethod = :httpMethod')
                ->setParameter('httpMethod', $filters['httpMethod']);
        }

        if (isset($filters['schemaType'])) {
            $qb->andWhere('s.schemaType = :schemaType')
                ->setParameter('schemaType', $filters['schemaType']);
        }

        if (isset($filters['masterOnly']) && $filters['masterOnly']) {
            $qb->andWhere('s.isMaster = :isMaster')
                ->setParameter('isMaster', true);
        }
    }
}
