<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\User;
use App\Repository\ApiTokenRepositoryInterface;
use App\Repository\RequestLogRepositoryInterface;
use App\Repository\SchemaDriftRepositoryInterface;
use App\Service\AccessControl\AccessControlServiceInterface;
use App\Service\HealthCheckServiceInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DashboardStatsService implements DashboardStatsServiceInterface
{
    public function __construct(
        private AccessControlServiceInterface $accessControlService,
        private ApiTokenRepositoryInterface $apiTokenRepository,
        private RequestLogRepositoryInterface $requestLogRepository,
        private SchemaDriftRepositoryInterface $schemaDriftRepository,
        private HealthCheckServiceInterface $healthCheckService,
    ) {
    }

    public function getOverviewStats(User $user): array
    {
        $since = new \DateTimeImmutable('-24 hours');

        return [
            'tokens' => $this->getTokenStats($user),
            'requests' => $this->getRequestStats($user, $since),
            'drifts' => $this->getDriftStats($user, $since),
            'health' => $this->healthCheckService->getHealthStatus(),
            'recentDrifts' => $this->getRecentDrifts($user),
            'services' => $this->getServiceHealthSummary($user),
        ];
    }

    public function getTokenStats(User $user): array
    {
        $tokens = $this->accessControlService->getAccessibleTokens($user);
        $tokenIds = array_map(fn ($token) => $token->getId(), $tokens);

        if (empty($tokenIds)) {
            return ['total' => 0, 'active' => 0, 'inactive' => 0];
        }

        $counts = $this->apiTokenRepository->countByActiveStatus($tokenIds);

        return [
            'total' => $counts['total'],
            'active' => $counts['active'],
            'inactive' => $counts['total'] - $counts['active'],
        ];
    }

    public function getRequestStats(User $user, \DateTimeInterface $since): array
    {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return ['last24h' => 0, 'trend' => []];
        }

        $count = $this->requestLogRepository->countSince($since, $tokenIds);
        $trend = $this->requestLogRepository->getHourlyTrend($since, $tokenIds);

        return [
            'last24h' => $count,
            'trend' => $trend,
        ];
    }

    public function getDriftStats(User $user, \DateTimeInterface $since): array
    {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return ['total' => 0, 'critical' => 0, 'warning' => 0, 'info' => 0];
        }

        $counts = $this->schemaDriftRepository->countBySeveritySince($since, $tokenIds);

        return [
            'total' => array_sum($counts),
            'critical' => $counts['critical'] ?? 0,
            'warning' => $counts['warning'] ?? 0,
            'info' => $counts['info'] ?? 0,
        ];
    }

    public function getRecentDrifts(User $user, int $limit = 5): array
    {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return [];
        }

        $drifts = $this->schemaDriftRepository->findRecentByTokenIds($tokenIds, $limit);

        return array_map(fn ($drift) => [
            'id' => $drift->getId()->toRfc4122(),
            'severity' => $drift->getSeverity()->value,
            'endpoint' => $drift->getSchema()->getEndpointPath(),
            'createdAt' => $drift->getCreatedAt(),
        ], $drifts);
    }

    public function getServiceHealthSummary(User $user): array
    {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return [];
        }

        $since = new \DateTimeImmutable('-1 hour');
        $hostStats = $this->requestLogRepository->getHostStats($since, $tokenIds);

        return array_map(function (array $stat) {
            $status = $this->calculateHealthStatus(
                (float) $stat['errorRate'],
                (int) $stat['avgLatencyMs']
            );

            return [
                'host' => $stat['host'],
                'status' => $status,
                'avgLatencyMs' => (int) $stat['avgLatencyMs'],
                'requestCount' => (int) $stat['requestCount'],
            ];
        }, $hostStats);
    }

    /**
     * @return list<Uuid>
     */
    private function getAccessibleTokenIds(User $user): array
    {
        $tokens = $this->accessControlService->getAccessibleTokens($user);

        return array_map(fn ($token) => $token->getId(), $tokens);
    }

    private function calculateHealthStatus(float $errorRate, int $avgLatencyMs): string
    {
        if ($errorRate > 5.0 || $avgLatencyMs > 1000) {
            return 'red';
        }

        if ($errorRate > 1.0 || $avgLatencyMs > 500) {
            return 'yellow';
        }

        return 'green';
    }
}
