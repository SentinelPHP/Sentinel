<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiSchema;
use App\Entity\GeneratedDto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<GeneratedDto>
 */
final class GeneratedDtoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GeneratedDto::class);
    }

    public function findCurrentBySchema(ApiSchema $schema): ?GeneratedDto
    {
        /** @var GeneratedDto|null */
        return $this->createQueryBuilder('d')
            ->andWhere('d.schema = :schema')
            ->andWhere('d.isCurrent = :isCurrent')
            ->setParameter('schema', $schema->getId(), 'uuid')
            ->setParameter('isCurrent', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<GeneratedDto>
     */
    public function findAllVersions(ApiSchema $schema): array
    {
        /** @var list<GeneratedDto> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.schema = :schema')
            ->setParameter('schema', $schema->getId(), 'uuid')
            ->orderBy('d.version', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByClassName(string $className): ?GeneratedDto
    {
        /** @var GeneratedDto|null */
        return $this->createQueryBuilder('d')
            ->andWhere('d.className = :className')
            ->andWhere('d.isCurrent = :isCurrent')
            ->setParameter('className', $className)
            ->setParameter('isCurrent', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByFullyQualifiedClassName(string $fqcn): ?GeneratedDto
    {
        $parts = explode('\\', $fqcn);
        $className = array_pop($parts);
        $namespace = implode('\\', $parts);

        /** @var GeneratedDto|null */
        return $this->createQueryBuilder('d')
            ->andWhere('d.className = :className')
            ->andWhere('d.namespace = :namespace')
            ->andWhere('d.isCurrent = :isCurrent')
            ->setParameter('className', $className)
            ->setParameter('namespace', $namespace)
            ->setParameter('isCurrent', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findBySchemaAndVersion(ApiSchema $schema, int $version): ?GeneratedDto
    {
        /** @var GeneratedDto|null */
        return $this->createQueryBuilder('d')
            ->andWhere('d.schema = :schema')
            ->andWhere('d.version = :version')
            ->setParameter('schema', $schema->getId(), 'uuid')
            ->setParameter('version', $version)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getNextVersion(ApiSchema $schema): int
    {
        $result = $this->createQueryBuilder('d')
            ->select('MAX(d.version)')
            ->andWhere('d.schema = :schema')
            ->setParameter('schema', $schema->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? ((int) $result) + 1 : 1;
    }

    public function clearCurrentFlag(ApiSchema $schema): void
    {
        $this->createQueryBuilder('d')
            ->update()
            ->set('d.isCurrent', ':isCurrent')
            ->andWhere('d.schema = :schema')
            ->setParameter('isCurrent', false)
            ->setParameter('schema', $schema->getId(), 'uuid')
            ->getQuery()
            ->execute();
    }

    public function findCurrentChecksum(ApiSchema $schema): ?string
    {
        /** @var array{checksum: string}|null $result */
        $result = $this->createQueryBuilder('d')
            ->select('d.checksum')
            ->andWhere('d.schema = :schema')
            ->andWhere('d.isCurrent = :isCurrent')
            ->setParameter('schema', $schema->getId(), 'uuid')
            ->setParameter('isCurrent', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null ? $result['checksum'] : null;
    }

    /**
     * @param list<Uuid> $schemaIds
     * @return list<GeneratedDto>
     */
    public function findCurrentBySchemaIds(array $schemaIds): array
    {
        if ($schemaIds === []) {
            return [];
        }

        /** @var list<GeneratedDto> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.schema IN (:schemaIds)')
            ->andWhere('d.isCurrent = :isCurrent')
            ->setParameter('schemaIds', $schemaIds)
            ->setParameter('isCurrent', true)
            ->orderBy('d.className', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{tokenIds?: list<Uuid>, schemaId?: Uuid, tokenId?: Uuid, className?: string, namespace?: string, endpointPath?: string} $filters
     * @return list<GeneratedDto>
     */
    public function findWithFilters(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.schema', 's')
            ->leftJoin('s.token', 't')
            ->addSelect('s')
            ->andWhere('d.isCurrent = :isCurrent')
            ->setParameter('isCurrent', true)
            ->orderBy('d.className', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (isset($filters['tokenIds']) && $filters['tokenIds'] !== []) {
            $qb->andWhere('s.token IN (:tokenIds)')
                ->setParameter('tokenIds', $filters['tokenIds']);
        }

        if (isset($filters['schemaId'])) {
            $qb->andWhere('d.schema = :schemaId')
                ->setParameter('schemaId', $filters['schemaId'], 'uuid');
        }

        if (isset($filters['tokenId'])) {
            $qb->andWhere('s.token = :tokenId')
                ->setParameter('tokenId', $filters['tokenId'], 'uuid');
        }

        if (isset($filters['className']) && $filters['className'] !== '') {
            $qb->andWhere('d.className LIKE :className')
                ->setParameter('className', '%' . $filters['className'] . '%');
        }

        if (isset($filters['namespace']) && $filters['namespace'] !== '') {
            $qb->andWhere('d.namespace LIKE :namespace')
                ->setParameter('namespace', '%' . $filters['namespace'] . '%');
        }

        if (isset($filters['endpointPath']) && $filters['endpointPath'] !== '') {
            $qb->andWhere('s.endpointPath LIKE :endpointPath')
                ->setParameter('endpointPath', '%' . $filters['endpointPath'] . '%');
        }

        /** @var list<GeneratedDto> */
        return $qb->getQuery()->getResult();
    }

    /**
     * @param array{tokenIds?: list<Uuid>, schemaId?: Uuid, tokenId?: Uuid, className?: string, namespace?: string, endpointPath?: string} $filters
     */
    public function countWithFilters(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->leftJoin('d.schema', 's')
            ->andWhere('d.isCurrent = :isCurrent')
            ->setParameter('isCurrent', true);

        if (isset($filters['tokenIds']) && $filters['tokenIds'] !== []) {
            $qb->andWhere('s.token IN (:tokenIds)')
                ->setParameter('tokenIds', $filters['tokenIds']);
        }

        if (isset($filters['schemaId'])) {
            $qb->andWhere('d.schema = :schemaId')
                ->setParameter('schemaId', $filters['schemaId'], 'uuid');
        }

        if (isset($filters['tokenId'])) {
            $qb->andWhere('s.token = :tokenId')
                ->setParameter('tokenId', $filters['tokenId'], 'uuid');
        }

        if (isset($filters['className']) && $filters['className'] !== '') {
            $qb->andWhere('d.className LIKE :className')
                ->setParameter('className', '%' . $filters['className'] . '%');
        }

        if (isset($filters['namespace']) && $filters['namespace'] !== '') {
            $qb->andWhere('d.namespace LIKE :namespace')
                ->setParameter('namespace', '%' . $filters['namespace'] . '%');
        }

        if (isset($filters['endpointPath']) && $filters['endpointPath'] !== '') {
            $qb->andWhere('s.endpointPath LIKE :endpointPath')
                ->setParameter('endpointPath', '%' . $filters['endpointPath'] . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
