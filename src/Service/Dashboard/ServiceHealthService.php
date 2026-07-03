<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\User;
use App\Repository\RequestLogRepositoryInterface;
use App\Repository\SchemaDriftRepositoryInterface;
use App\Service\AccessControl\AccessControlServiceInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ServiceHealthService implements ServiceHealthServiceInterface
{
    private const DEFAULT_ERROR_RATE_YELLOW = 1.0;
    private const DEFAULT_ERROR_RATE_RED = 5.0;
    private const DEFAULT_LATENCY_YELLOW = 500;
    private const DEFAULT_LATENCY_RED = 1000;

    public function __construct(
        private AccessControlServiceInterface $accessControlService,
        private RequestLogRepositoryInterface $requestLogRepository,
        private SchemaDriftRepositoryInterface $schemaDriftRepository,
        private ?HealthStatusTrackerServiceInterface $healthStatusTracker = null,
    ) {
    }

    public function getAllServicesHealth(User $user): array
    {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return [];
        }

        $since = new \DateTimeImmutable('-1 hour');
        $driftSince = new \DateTimeImmutable('-24 hours');
        $hostStats = $this->requestLogRepository->getHostStats($since, $tokenIds);

        return array_map(function (array $stat) use ($tokenIds, $driftSince) {
            $driftCounts = $this->schemaDriftRepository->countBySeverityForHost(
                $driftSince,
                $tokenIds,
                $stat['host']
            );

            $status = $this->calculateHealthStatus(
                (float) $stat['errorRate'],
                (int) $stat['avgLatencyMs'],
                $driftCounts['critical']
            );

            $this->healthStatusTracker?->trackHealthStatus($stat['host'], $status);

            return [
                'host' => $stat['host'],
                'status' => $status,
                'avgLatencyMs' => (int) $stat['avgLatencyMs'],
                'requestCount' => (int) $stat['requestCount'],
                'errorRate' => (float) $stat['errorRate'],
                'criticalDrifts' => $driftCounts['critical'],
                'warningDrifts' => $driftCounts['warning'],
            ];
        }, $hostStats);
    }

    public function getServiceHealthByHost(User $user, string $host): ?array
    {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return null;
        }

        $since = new \DateTimeImmutable('-1 hour');
        $driftSince = new \DateTimeImmutable('-24 hours');

        $stats = $this->requestLogRepository->getHostDetailedStats($since, $tokenIds, $host);

        if ($stats === null) {
            return null;
        }

        $driftCounts = $this->schemaDriftRepository->countBySeverityForHost($driftSince, $tokenIds, $host);
        $recentRequests = $this->requestLogRepository->getRecentRequestsByHost($tokenIds, $host, 10);
        $recentDrifts = $this->schemaDriftRepository->findRecentByHost($tokenIds, $host, 10);

        $status = $this->calculateHealthStatus(
            $stats['errorRate'],
            $stats['avgLatencyMs'],
            $driftCounts['critical']
        );

        return [
            'host' => $stats['host'],
            'status' => $status,
            'avgLatencyMs' => $stats['avgLatencyMs'],
            'p50LatencyMs' => $stats['p50LatencyMs'],
            'p95LatencyMs' => $stats['p95LatencyMs'],
            'p99LatencyMs' => $stats['p99LatencyMs'],
            'requestCount' => $stats['requestCount'],
            'errorRate' => $stats['errorRate'],
            'criticalDrifts' => $driftCounts['critical'],
            'warningDrifts' => $driftCounts['warning'],
            'infoDrifts' => $driftCounts['info'],
            'recentRequests' => $recentRequests,
            'recentDrifts' => array_map(fn ($drift) => [
                'id' => $drift->getId()->toRfc4122(),
                'severity' => $drift->getSeverity()->value,
                'path' => $drift->getPath(),
                'createdAt' => $drift->getCreatedAt(),
            ], $recentDrifts),
        ];
    }

    public function getHealthHistory(User $user, string $host, \DateTimeInterface $since): array
    {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return [];
        }

        $history = $this->requestLogRepository->getHostHealthHistory($since, $tokenIds, $host);

        return array_map(function (array $row) {
            return [
                'hour' => $row['hour'],
                'status' => $this->calculateHealthStatus(
                    $row['errorRate'],
                    $row['avgLatencyMs']
                ),
                'avgLatencyMs' => $row['avgLatencyMs'],
                'requestCount' => $row['requestCount'],
                'errorRate' => $row['errorRate'],
            ];
        }, $history);
    }

    public function calculateHealthStatus(
        float $errorRate,
        int $avgLatencyMs,
        int $criticalDrifts = 0,
        ?array $thresholds = null
    ): string {
        $errorRateYellow = $thresholds['errorRateYellow'] ?? self::DEFAULT_ERROR_RATE_YELLOW;
        $errorRateRed = $thresholds['errorRateRed'] ?? self::DEFAULT_ERROR_RATE_RED;
        $latencyYellow = $thresholds['latencyYellow'] ?? self::DEFAULT_LATENCY_YELLOW;
        $latencyRed = $thresholds['latencyRed'] ?? self::DEFAULT_LATENCY_RED;

        if ($errorRate > $errorRateRed || $avgLatencyMs > $latencyRed || $criticalDrifts > 0) {
            return 'red';
        }

        if ($errorRate > $errorRateYellow || $avgLatencyMs > $latencyYellow) {
            return 'yellow';
        }

        return 'green';
    }

    /**
     * @return list<Uuid>
     */
    private function getAccessibleTokenIds(User $user): array
    {
        $tokens = $this->accessControlService->getAccessibleTokens($user);

        return array_map(fn ($token) => $token->getId(), $tokens);
    }
}
