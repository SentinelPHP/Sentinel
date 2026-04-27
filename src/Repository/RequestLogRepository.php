<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RequestLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<RequestLog>
 */
class RequestLogRepository extends ServiceEntityRepository implements RequestLogRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RequestLog::class);
    }

    /**
     * Count requests since a given date for the specified tokens.
     *
     * @param list<Uuid> $tokenIds
     */
    public function countSince(\DateTimeInterface $since, array $tokenIds): int
    {
        if (empty($tokenIds)) {
            return 0;
        }

        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.createdAt >= :since')
            ->andWhere('r.token IN (:tokenIds)')
            ->setParameter('since', $since)
            ->setParameter('tokenIds', $tokenIds);

        /** @var int */
        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get hourly request counts for trend visualization.
     *
     * @param list<Uuid> $tokenIds
     * @return array<string, int>
     */
    public function getHourlyTrend(\DateTimeInterface $since, array $tokenIds): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $tokenIdStrings = array_map(fn (Uuid $id) => $id->toRfc4122(), $tokenIds);

        $sql = "SELECT TO_CHAR(created_at, 'YYYY-MM-DD HH24:00') as hour, COUNT(id) as count
                FROM request_logs
                WHERE created_at >= :since
                AND token_id IN (:tokenIds)
                GROUP BY hour
                ORDER BY hour ASC";

        /** @var list<array{hour: string, count: int|string}> $results */
        $results = $conn->executeQuery($sql, [
            'since' => $since->format('Y-m-d H:i:s'),
            'tokenIds' => $tokenIdStrings,
        ], [
            'tokenIds' => \Doctrine\DBAL\ArrayParameterType::STRING,
        ])->fetchAllAssociative();

        $trend = [];
        foreach ($results as $row) {
            $trend[$row['hour']] = (int) $row['count'];
        }

        return $trend;
    }

    /**
     * Get statistics per target host.
     *
     * @param list<Uuid> $tokenIds
     * @return list<array{host: string, avgLatencyMs: int|float, requestCount: int, errorRate: float}>
     */
    public function getHostStats(\DateTimeInterface $since, array $tokenIds): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $tokenIdStrings = array_map(fn (Uuid $id) => $id->toRfc4122(), $tokenIds);

        $sql = "SELECT 
                    target_host as host,
                    AVG(latency_ms) as \"avgLatencyMs\",
                    COUNT(id) as \"requestCount\",
                    (SUM(CASE WHEN response_status_code >= 500 THEN 1 ELSE 0 END)::float / COUNT(id)) * 100 as \"errorRate\"
                FROM request_logs
                WHERE created_at >= :since
                AND token_id IN (:tokenIds)
                GROUP BY target_host
                ORDER BY \"requestCount\" DESC
                LIMIT 10";

        /** @var list<array{host: string, avgLatencyMs: numeric-string, requestCount: numeric-string, errorRate: numeric-string}> $results */
        $results = $conn->executeQuery($sql, [
            'since' => $since->format('Y-m-d H:i:s'),
            'tokenIds' => $tokenIdStrings,
        ], [
            'tokenIds' => \Doctrine\DBAL\ArrayParameterType::STRING,
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'host' => $row['host'],
            'avgLatencyMs' => (float) $row['avgLatencyMs'],
            'requestCount' => (int) $row['requestCount'],
            'errorRate' => (float) $row['errorRate'],
        ], $results);
    }

    /**
     * Get detailed statistics for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return array{host: string, avgLatencyMs: int, p50LatencyMs: int, p95LatencyMs: int, p99LatencyMs: int, requestCount: int, errorRate: float}|null
     */
    public function getHostDetailedStats(\DateTimeInterface $since, array $tokenIds, string $host): ?array
    {
        if (empty($tokenIds)) {
            return null;
        }

        $conn = $this->getEntityManager()->getConnection();
        $tokenIdStrings = array_map(fn (Uuid $id) => $id->toRfc4122(), $tokenIds);

        $sql = "SELECT 
                    target_host as host,
                    AVG(latency_ms)::int as \"avgLatencyMs\",
                    PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY latency_ms)::int as \"p50LatencyMs\",
                    PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY latency_ms)::int as \"p95LatencyMs\",
                    PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY latency_ms)::int as \"p99LatencyMs\",
                    COUNT(id)::int as \"requestCount\",
                    (SUM(CASE WHEN response_status_code >= 500 THEN 1 ELSE 0 END)::float / NULLIF(COUNT(id), 0)) * 100 as \"errorRate\"
                FROM request_logs
                WHERE created_at >= :since
                AND token_id IN (:tokenIds)
                AND target_host = :host
                GROUP BY target_host";

        /** @var array{host: string, avgLatencyMs: int|string|null, p50LatencyMs: int|string|null, p95LatencyMs: int|string|null, p99LatencyMs: int|string|null, requestCount: int|string, errorRate: float|string|null}|false $result */
        $result = $conn->executeQuery($sql, [
            'since' => $since->format('Y-m-d H:i:s'),
            'tokenIds' => $tokenIdStrings,
            'host' => $host,
        ], [
            'tokenIds' => \Doctrine\DBAL\ArrayParameterType::STRING,
        ])->fetchAssociative();

        if ($result === false) {
            return null;
        }

        return [
            'host' => $result['host'],
            'avgLatencyMs' => (int) ($result['avgLatencyMs'] ?? 0),
            'p50LatencyMs' => (int) ($result['p50LatencyMs'] ?? 0),
            'p95LatencyMs' => (int) ($result['p95LatencyMs'] ?? 0),
            'p99LatencyMs' => (int) ($result['p99LatencyMs'] ?? 0),
            'requestCount' => (int) $result['requestCount'],
            'errorRate' => (float) ($result['errorRate'] ?? 0),
        ];
    }

    /**
     * Get hourly health history for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return list<array{hour: string, avgLatencyMs: int, requestCount: int, errorRate: float}>
     */
    public function getHostHealthHistory(\DateTimeInterface $since, array $tokenIds, string $host): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $tokenIdStrings = array_map(fn (Uuid $id) => $id->toRfc4122(), $tokenIds);

        $sql = "SELECT 
                    TO_CHAR(created_at, 'YYYY-MM-DD HH24:00') as hour,
                    AVG(latency_ms)::int as \"avgLatencyMs\",
                    COUNT(id)::int as \"requestCount\",
                    (SUM(CASE WHEN response_status_code >= 500 THEN 1 ELSE 0 END)::float / NULLIF(COUNT(id), 0)) * 100 as \"errorRate\"
                FROM request_logs
                WHERE created_at >= :since
                AND token_id IN (:tokenIds)
                AND target_host = :host
                GROUP BY hour
                ORDER BY hour ASC";

        /** @var list<array{hour: string, avgLatencyMs: int|string|null, requestCount: int|string, errorRate: float|string|null}> $results */
        $results = $conn->executeQuery($sql, [
            'since' => $since->format('Y-m-d H:i:s'),
            'tokenIds' => $tokenIdStrings,
            'host' => $host,
        ], [
            'tokenIds' => \Doctrine\DBAL\ArrayParameterType::STRING,
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'hour' => $row['hour'],
            'avgLatencyMs' => (int) ($row['avgLatencyMs'] ?? 0),
            'requestCount' => (int) $row['requestCount'],
            'errorRate' => (float) ($row['errorRate'] ?? 0),
        ], $results);
    }

    /**
     * Get recent requests for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return list<array{id: string, method: string, path: string, statusCode: int, latencyMs: int, createdAt: \DateTimeImmutable}>
     */
    public function getRecentRequestsByHost(array $tokenIds, string $host, int $limit = 10): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        /** @var list<RequestLog> $results */
        $results = $this->createQueryBuilder('r')
            ->where('r.token IN (:tokenIds)')
            ->andWhere('r.targetHost = :host')
            ->setParameter('tokenIds', $tokenIds)
            ->setParameter('host', $host)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (RequestLog $log): array => [
            'id' => $log->getId()->toRfc4122(),
            'method' => $log->getRequestMethod(),
            'path' => $log->getRequestPath(),
            'statusCode' => $log->getResponseStatusCode(),
            'latencyMs' => $log->getLatencyMs(),
            'createdAt' => $log->getCreatedAt(),
        ], $results);
    }

    /**
     * Get min/max latency for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return array{min: int, max: int}
     */
    public function getHostLatencyRange(\DateTimeInterface $since, array $tokenIds, string $host): array
    {
        if (empty($tokenIds)) {
            return ['min' => 0, 'max' => 0];
        }

        $conn = $this->getEntityManager()->getConnection();
        $tokenIdStrings = array_map(fn (Uuid $id) => $id->toRfc4122(), $tokenIds);

        $sql = "SELECT 
                    COALESCE(MIN(latency_ms), 0)::int as \"minLatency\",
                    COALESCE(MAX(latency_ms), 0)::int as \"maxLatency\"
                FROM request_logs
                WHERE created_at >= :since
                AND token_id IN (:tokenIds)
                AND target_host = :host";

        /** @var array{minLatency: int|string, maxLatency: int|string}|false $result */
        $result = $conn->executeQuery($sql, [
            'since' => $since->format('Y-m-d H:i:s'),
            'tokenIds' => $tokenIdStrings,
            'host' => $host,
        ], [
            'tokenIds' => \Doctrine\DBAL\ArrayParameterType::STRING,
        ])->fetchAssociative();

        if ($result === false) {
            return ['min' => 0, 'max' => 0];
        }

        return [
            'min' => (int) $result['minLatency'],
            'max' => (int) $result['maxLatency'],
        ];
    }

    /**
     * Get latency time series with percentiles for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return list<array{bucket: string, avgLatencyMs: int, p50LatencyMs: int, p95LatencyMs: int, requestCount: int}>
     */
    public function getHostLatencyTimeSeries(\DateTimeInterface $since, array $tokenIds, string $host, int $bucketMinutes = 5): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $tokenIdStrings = array_map(fn (Uuid $id) => $id->toRfc4122(), $tokenIds);

        $sql = "SELECT 
                    TO_CHAR(
                        DATE_TRUNC('hour', created_at) + 
                        INTERVAL '1 minute' * (EXTRACT(MINUTE FROM created_at)::int / :bucket * :bucket),
                        'YYYY-MM-DD HH24:MI'
                    ) as bucket,
                    AVG(latency_ms)::int as \"avgLatencyMs\",
                    PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY latency_ms)::int as \"p50LatencyMs\",
                    PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY latency_ms)::int as \"p95LatencyMs\",
                    COUNT(id)::int as \"requestCount\"
                FROM request_logs
                WHERE created_at >= :since
                AND token_id IN (:tokenIds)
                AND target_host = :host
                GROUP BY bucket
                ORDER BY bucket ASC";

        /** @var list<array{bucket: string, avgLatencyMs: int|string|null, p50LatencyMs: int|string|null, p95LatencyMs: int|string|null, requestCount: int|string}> $results */
        $results = $conn->executeQuery($sql, [
            'since' => $since->format('Y-m-d H:i:s'),
            'tokenIds' => $tokenIdStrings,
            'host' => $host,
            'bucket' => $bucketMinutes,
        ], [
            'tokenIds' => \Doctrine\DBAL\ArrayParameterType::STRING,
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'bucket' => $row['bucket'],
            'avgLatencyMs' => (int) ($row['avgLatencyMs'] ?? 0),
            'p50LatencyMs' => (int) ($row['p50LatencyMs'] ?? 0),
            'p95LatencyMs' => (int) ($row['p95LatencyMs'] ?? 0),
            'requestCount' => (int) $row['requestCount'],
        ], $results);
    }

    /**
     * Get recent requests for a specific token.
     *
     * @return list<array{id: string, method: string, path: string, statusCode: int, latencyMs: int, createdAt: \DateTimeImmutable}>
     */
    public function getRecentRequestsByToken(Uuid $tokenId, int $limit = 10): array
    {
        /** @var list<RequestLog> $results */
        $results = $this->createQueryBuilder('r')
            ->where('r.token = :tokenId')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (RequestLog $log): array => [
            'id' => $log->getId()->toRfc4122(),
            'method' => $log->getRequestMethod(),
            'path' => $log->getRequestPath(),
            'statusCode' => $log->getResponseStatusCode(),
            'latencyMs' => $log->getLatencyMs(),
            'createdAt' => $log->getCreatedAt(),
        ], $results);
    }

    /**
     * Count requests for a specific token.
     */
    public function countByToken(Uuid $tokenId): int
    {
        /** @var int */
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.token = :tokenId')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find requests for a token with pagination.
     *
     * @return list<RequestLog>
     */
    public function findByTokenPaginated(Uuid $tokenId, int $limit = 50, int $offset = 0): array
    {
        /** @var list<RequestLog> */
        return $this->createQueryBuilder('r')
            ->where('r.token = :tokenId')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find request logs with filters for the log explorer.
     *
     * @param array{
     *     tokenIds?: list<Uuid>,
     *     tokenId?: Uuid,
     *     targetHost?: string,
     *     method?: string,
     *     pathSearch?: string,
     *     statusCode?: int,
     *     statusCodeRange?: string,
     *     latencyMin?: int,
     *     latencyMax?: int,
     *     hasDrift?: bool,
     *     from?: \DateTimeImmutable,
     *     to?: \DateTimeImmutable
     * } $filters
     * @return list<RequestLog>
     */
    public function findWithFilters(array $filters, int $limit = 25, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.token', 't')
            ->addSelect('t');

        $this->applyFilters($qb, $filters);

        /** @var list<RequestLog> */
        return $qb
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count request logs with filters.
     *
     * @param array{
     *     tokenIds?: list<Uuid>,
     *     tokenId?: Uuid,
     *     targetHost?: string,
     *     method?: string,
     *     pathSearch?: string,
     *     statusCode?: int,
     *     statusCodeRange?: string,
     *     latencyMin?: int,
     *     latencyMax?: int,
     *     hasDrift?: bool,
     *     from?: \DateTimeImmutable,
     *     to?: \DateTimeImmutable
     * } $filters
     */
    public function countWithFilters(array $filters): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)');

        $this->applyFilters($qb, $filters);

        /** @var int */
        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get distinct target hosts for filter dropdown.
     *
     * @param list<Uuid> $tokenIds
     * @return list<string>
     */
    public function getDistinctTargetHosts(array $tokenIds): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        /** @var list<array{targetHost: string}> $results */
        $results = $this->createQueryBuilder('r')
            ->select('DISTINCT r.targetHost')
            ->where('r.token IN (:tokenIds)')
            ->setParameter('tokenIds', $tokenIds)
            ->orderBy('r.targetHost', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($results, 'targetHost');
    }

    /**
     * Get distinct HTTP methods for filter dropdown.
     *
     * @param list<Uuid> $tokenIds
     * @return list<string>
     */
    public function getDistinctMethods(array $tokenIds): array
    {
        if (empty($tokenIds)) {
            return [];
        }

        /** @var list<array{requestMethod: string}> $results */
        $results = $this->createQueryBuilder('r')
            ->select('DISTINCT r.requestMethod')
            ->where('r.token IN (:tokenIds)')
            ->setParameter('tokenIds', $tokenIds)
            ->orderBy('r.requestMethod', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($results, 'requestMethod');
    }

    /**
     * Apply filters to query builder.
     *
     * @param \Doctrine\ORM\QueryBuilder $qb
     * @param array{
     *     tokenIds?: list<Uuid>,
     *     tokenId?: Uuid,
     *     targetHost?: string,
     *     method?: string,
     *     pathSearch?: string,
     *     statusCode?: int,
     *     statusCodeRange?: string,
     *     latencyMin?: int,
     *     latencyMax?: int,
     *     hasDrift?: bool,
     *     from?: \DateTimeImmutable,
     *     to?: \DateTimeImmutable
     * } $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['tokenIds']) && $filters['tokenIds'] !== []) {
            $qb->andWhere('r.token IN (:tokenIds)')
                ->setParameter('tokenIds', $filters['tokenIds']);
        }

        if (isset($filters['tokenId'])) {
            $qb->andWhere('r.token = :tokenId')
                ->setParameter('tokenId', $filters['tokenId'], 'uuid');
        }

        if (isset($filters['targetHost']) && $filters['targetHost'] !== '') {
            $qb->andWhere('r.targetHost = :targetHost')
                ->setParameter('targetHost', $filters['targetHost']);
        }

        if (isset($filters['method']) && $filters['method'] !== '') {
            $qb->andWhere('r.requestMethod = :method')
                ->setParameter('method', $filters['method']);
        }

        if (isset($filters['pathSearch']) && $filters['pathSearch'] !== '') {
            $qb->andWhere('r.requestPath LIKE :pathSearch')
                ->setParameter('pathSearch', '%' . $filters['pathSearch'] . '%');
        }

        if (isset($filters['statusCode'])) {
            $qb->andWhere('r.responseStatusCode = :statusCode')
                ->setParameter('statusCode', $filters['statusCode']);
        }

        if (isset($filters['statusCodeRange']) && $filters['statusCodeRange'] !== '') {
            match ($filters['statusCodeRange']) {
                '2xx' => $qb->andWhere('r.responseStatusCode >= 200 AND r.responseStatusCode < 300'),
                '3xx' => $qb->andWhere('r.responseStatusCode >= 300 AND r.responseStatusCode < 400'),
                '4xx' => $qb->andWhere('r.responseStatusCode >= 400 AND r.responseStatusCode < 500'),
                '5xx' => $qb->andWhere('r.responseStatusCode >= 500 AND r.responseStatusCode < 600'),
                default => null,
            };
        }

        if (isset($filters['latencyMin'])) {
            $qb->andWhere('r.latencyMs >= :latencyMin')
                ->setParameter('latencyMin', $filters['latencyMin']);
        }

        if (isset($filters['latencyMax'])) {
            $qb->andWhere('r.latencyMs <= :latencyMax')
                ->setParameter('latencyMax', $filters['latencyMax']);
        }

        if (isset($filters['hasDrift'])) {
            if ($filters['hasDrift']) {
                $qb->andWhere('r.driftDetected = true');
            } else {
                $qb->andWhere('r.driftDetected = false OR r.driftDetected IS NULL');
            }
        }

        if (isset($filters['from'])) {
            $qb->andWhere('r.createdAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('r.createdAt <= :to')
                ->setParameter('to', $filters['to']);
        }
    }
}
