<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AlertConfiguration;
use App\Entity\ApiToken;
use App\Enum\AlertChannelType;
use SentinelPHP\Drift\Enum\DriftSeverity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AlertConfiguration>
 */
final class AlertConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertConfiguration::class);
    }

    /**
     * Find all active configurations for a specific token, including global configurations.
     *
     * @return list<AlertConfiguration>
     */
    public function findActiveForToken(ApiToken $token): array
    {
        /** @var list<AlertConfiguration> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.isActive = :active')
            ->andWhere('c.token = :token OR c.token IS NULL')
            ->setParameter('active', true)
            ->setParameter('token', $token->getId(), 'uuid')
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active global configurations (token_id IS NULL).
     *
     * @return list<AlertConfiguration>
     */
    public function findGlobalActive(): array
    {
        /** @var list<AlertConfiguration> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.isActive = :active')
            ->andWhere('c.token IS NULL')
            ->setParameter('active', true)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find configurations by channel type.
     *
     * @return list<AlertConfiguration>
     */
    public function findByChannelType(AlertChannelType $channelType): array
    {
        /** @var list<AlertConfiguration> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.channelType = :channelType')
            ->setParameter('channelType', $channelType)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all configurations for a specific token (excluding global).
     *
     * @return list<AlertConfiguration>
     */
    public function findByTokenId(Uuid $tokenId): array
    {
        /** @var list<AlertConfiguration> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.token = :tokenId')
            ->setParameter('tokenId', $tokenId, 'uuid')
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active configurations that should alert for the given severity.
     *
     * @return list<AlertConfiguration>
     */
    public function findActiveForTokenAndSeverity(ApiToken $token, DriftSeverity $severity): array
    {
        $severityOrder = [
            DriftSeverity::Info->value => 0,
            DriftSeverity::Warning->value => 1,
            DriftSeverity::Critical->value => 2,
        ];

        $configs = $this->findActiveForToken($token);

        return array_values(array_filter(
            $configs,
            static fn (AlertConfiguration $c) => $severityOrder[$severity->value] >= $severityOrder[$c->getMinSeverity()->value]
        ));
    }

    /**
     * Count configurations by channel type.
     *
     * @return array<string, int>
     */
    public function countByChannelType(): array
    {
        /** @var list<array{channelType: AlertChannelType, count: int|string}> $results */
        $results = $this->createQueryBuilder('c')
            ->select('c.channelType as channelType, COUNT(c.id) as count')
            ->groupBy('c.channelType')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['channelType']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Find configurations with filters for dashboard listing.
     *
     * @param array{channelType?: string, isActive?: bool, isGlobal?: bool} $filters
     * @return list<AlertConfiguration>
     */
    public function findWithFilters(array $filters, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.token', 't')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $this->applyFilters($qb, $filters);

        /** @var list<AlertConfiguration> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Count configurations with filters.
     *
     * @param array{channelType?: string, isActive?: bool, isGlobal?: bool} $filters
     */
    public function countWithFilters(array $filters): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)');

        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array{channelType?: string, isActive?: bool, isGlobal?: bool} $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['channelType'])) {
            $channelType = AlertChannelType::tryFrom($filters['channelType']);
            if ($channelType !== null) {
                $qb->andWhere('c.channelType = :channelType')
                    ->setParameter('channelType', $channelType);
            }
        }

        if (isset($filters['isActive'])) {
            $qb->andWhere('c.isActive = :isActive')
                ->setParameter('isActive', $filters['isActive']);
        }

        if (isset($filters['isGlobal'])) {
            if ($filters['isGlobal']) {
                $qb->andWhere('c.token IS NULL');
            } else {
                $qb->andWhere('c.token IS NOT NULL');
            }
        }
    }

    /**
     * Find latency threshold configuration for a specific host.
     * Looks for configurations with latency thresholds in channelConfig.
     */
    public function findLatencyThresholdsForHost(string $host): ?AlertConfiguration
    {
        /** @var list<AlertConfiguration> $configs */
        $configs = $this->createQueryBuilder('c')
            ->andWhere('c.isActive = :active')
            ->andWhere('c.token IS NULL')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();

        foreach ($configs as $config) {
            $channelConfig = $config->getChannelConfig();

            if (isset($channelConfig['latencyWarning']) || isset($channelConfig['latencyCritical'])) {
                $hosts = $channelConfig['hosts'] ?? [];

                if (!is_array($hosts) || $hosts === [] || in_array($host, $hosts, true)) {
                    return $config;
                }
            }
        }

        return null;
    }
}
