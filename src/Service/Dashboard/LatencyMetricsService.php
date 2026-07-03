<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\User;
use App\Redis\RedisClientInterface;
use App\Repository\RequestLogRepositoryInterface;
use App\Service\AccessControl\AccessControlServiceInterface;
use Symfony\Component\Uid\Uuid;

final readonly class LatencyMetricsService implements LatencyMetricsServiceInterface
{
    private const REDIS_KEY_PREFIX = 'latency:';
    private const ROLLING_1M_TTL = 120;
    private const ROLLING_5M_TTL = 600;
    private const ROLLING_1H_TTL = 7200;
    private const SAMPLES_TTL = 3600;
    private const MAX_SAMPLES = 1000;

    private const TREND_THRESHOLD_PERCENT = 10.0;

    public function __construct(
        private AccessControlServiceInterface $accessControlService,
        private RequestLogRepositoryInterface $requestLogRepository,
        private RedisClientInterface $redisClient,
    ) {
    }

    public function getPercentiles(User $user, string $host, ?\DateTimeInterface $since = null): array
    {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return $this->emptyPercentiles();
        }

        $since ??= new \DateTimeImmutable('-1 hour');
        $stats = $this->requestLogRepository->getHostDetailedStats($since, $tokenIds, $host);

        if ($stats === null) {
            return $this->emptyPercentiles();
        }

        $range = $this->getLatencyRange($since, $tokenIds, $host);

        return [
            'p50' => $stats['p50LatencyMs'],
            'p95' => $stats['p95LatencyMs'],
            'p99' => $stats['p99LatencyMs'],
            'avg' => $stats['avgLatencyMs'],
            'min' => $range['min'],
            'max' => $range['max'],
        ];
    }

    /**
     * @return array<string, int|null>
     */
    public function getRollingAverages(User $user, string $host): array
    {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (count($tokenIds) === 0) {
            return ['1m' => null, '5m' => null, '1h' => null];
        }

        $hostKey = $this->normalizeHostKey($host);

        return [
            '1m' => $this->getRollingAverage($hostKey, '1m'),
            '5m' => $this->getRollingAverage($hostKey, '5m'),
            '1h' => $this->getRollingAverage($hostKey, '1h'),
        ];
    }

    public function getTrend(User $user, string $host): string
    {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return 'stable';
        }

        $hostKey = $this->normalizeHostKey($host);
        $samples = $this->getLatencySamples($hostKey);

        if (count($samples) < 10) {
            return 'stable';
        }

        $halfPoint = (int) (count($samples) / 2);
        $recentSamples = array_slice($samples, 0, $halfPoint);
        $olderSamples = array_slice($samples, $halfPoint);

        if (empty($recentSamples) || empty($olderSamples)) {
            return 'stable';
        }

        $recentAvg = array_sum($recentSamples) / count($recentSamples);
        $olderAvg = array_sum($olderSamples) / count($olderSamples);

        if ($olderAvg == 0) {
            return 'stable';
        }

        $changePercent = (($recentAvg - $olderAvg) / $olderAvg) * 100;

        if ($changePercent < -self::TREND_THRESHOLD_PERCENT) {
            return 'improving';
        }

        if ($changePercent > self::TREND_THRESHOLD_PERCENT) {
            return 'degrading';
        }

        return 'stable';
    }

    public function getLatencyTimeSeries(User $user, string $host, \DateTimeInterface $since, string $bucket = '5m'): array
    {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return [];
        }

        $bucketMinutes = $this->parseBucketMinutes($bucket);
        $history = $this->requestLogRepository->getHostHealthHistory($since, $tokenIds, $host);

        if (empty($history)) {
            return [];
        }

        $grouped = [];

        foreach ($history as $row) {
            $timestamp = new \DateTimeImmutable($row['hour']);
            $bucketKey = $this->getBucketKey($timestamp, $bucketMinutes);

            if (!isset($grouped[$bucketKey])) {
                $grouped[$bucketKey] = [];
            }

            $grouped[$bucketKey][] = [
                'avgLatencyMs' => $row['avgLatencyMs'],
                'requestCount' => $row['requestCount'],
            ];
        }

        $timeSeries = [];
        foreach ($grouped as $bucketKey => $bucketData) {
            $timeSeries[$bucketKey] = $this->aggregateBucketData($bucketData);
        }

        return $timeSeries;
    }

    public function getLatencyComparison(
        User $user,
        string $host,
        \DateTimeInterface $currentStart,
        \DateTimeInterface $baselineStart,
        \DateInterval $period
    ): array {
        $tokenIds = $this->getAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return $this->emptyComparison();
        }

        $currentEnd = \DateTimeImmutable::createFromInterface($currentStart)->add($period);
        $baselineEnd = \DateTimeImmutable::createFromInterface($baselineStart)->add($period);

        $currentStats = $this->requestLogRepository->getHostDetailedStats($currentStart, $tokenIds, $host);
        $baselineStats = $this->requestLogRepository->getHostDetailedStats($baselineStart, $tokenIds, $host);

        $current = $currentStats !== null ? [
            'p50' => $currentStats['p50LatencyMs'],
            'p95' => $currentStats['p95LatencyMs'],
            'p99' => $currentStats['p99LatencyMs'],
            'avg' => $currentStats['avgLatencyMs'],
        ] : ['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0];

        $baseline = $baselineStats !== null ? [
            'p50' => $baselineStats['p50LatencyMs'],
            'p95' => $baselineStats['p95LatencyMs'],
            'p99' => $baselineStats['p99LatencyMs'],
            'avg' => $baselineStats['avgLatencyMs'],
        ] : ['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0];

        return [
            'current' => $current,
            'baseline' => $baseline,
            'change' => [
                'p50' => $this->calculateChangePercent($current['p50'], $baseline['p50']),
                'p95' => $this->calculateChangePercent($current['p95'], $baseline['p95']),
                'p99' => $this->calculateChangePercent($current['p99'], $baseline['p99']),
                'avg' => $this->calculateChangePercent($current['avg'], $baseline['avg']),
            ],
        ];
    }

    public function recordLatencySample(string $host, int $latencyMs): void
    {
        $hostKey = $this->normalizeHostKey($host);
        $timestamp = time();

        $this->pushLatencySample($hostKey, $latencyMs, $timestamp);
        $this->updateRollingAverages($hostKey, $latencyMs, $timestamp);
    }

    /**
     * @return list<Uuid>
     */
    private function getAccessibleTokenIds(User $user): array
    {
        $tokens = $this->accessControlService->getAccessibleTokens($user);

        return array_map(fn ($token) => $token->getId(), $tokens);
    }

    /**
     * @return array{p50: int, p95: int, p99: int, avg: int, min: int, max: int}
     */
    private function emptyPercentiles(): array
    {
        return ['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0, 'min' => 0, 'max' => 0];
    }

    /**
     * @return array{current: array{p50: int, p95: int, p99: int, avg: int}, baseline: array{p50: int, p95: int, p99: int, avg: int}, change: array{p50: float, p95: float, p99: float, avg: float}}
     */
    private function emptyComparison(): array
    {
        $empty = ['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0];

        return [
            'current' => $empty,
            'baseline' => $empty,
            'change' => ['p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0, 'avg' => 0.0],
        ];
    }

    /**
     * @param list<Uuid> $tokenIds
     * @return array{min: int, max: int}
     */
    private function getLatencyRange(\DateTimeInterface $since, array $tokenIds, string $host): array
    {
        return $this->requestLogRepository->getHostLatencyRange($since, $tokenIds, $host);
    }

    private function normalizeHostKey(string $host): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $host) ?? $host;
    }

    private function getRollingAverage(string $hostKey, string $window): ?int
    {
        $key = self::REDIS_KEY_PREFIX . "rolling:{$hostKey}:{$window}";
        $value = $this->redisClient->get($key);

        return $value !== null ? (int) $value : null;
    }

    /**
     * @return list<int>
     */
    private function getLatencySamples(string $hostKey): array
    {
        $key = self::REDIS_KEY_PREFIX . "samples:{$hostKey}";
        $data = $this->redisClient->get($key);

        if ($data === null) {
            return [];
        }

        $decoded = json_decode($data, true);

        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $item) {
            if (is_array($item) && isset($item['latency']) && is_numeric($item['latency'])) {
                $result[] = (int) $item['latency'];
            }
        }

        return $result;
    }

    private function pushLatencySample(string $hostKey, int $latencyMs, int $timestamp): void
    {
        $key = self::REDIS_KEY_PREFIX . "samples:{$hostKey}";
        $data = $this->redisClient->get($key);

        $samples = [];
        if ($data !== null) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $samples = $decoded;
            }
        }

        array_unshift($samples, ['latency' => $latencyMs, 'timestamp' => $timestamp]);

        if (count($samples) > self::MAX_SAMPLES) {
            $samples = array_slice($samples, 0, self::MAX_SAMPLES);
        }

        $encoded = json_encode($samples);
        if ($encoded !== false) {
            $this->redisClient->setex($key, self::SAMPLES_TTL, $encoded);
        }
    }

    private function updateRollingAverages(string $hostKey, int $latencyMs, int $timestamp): void
    {
        $this->updateRollingAverage($hostKey, '1m', $latencyMs, 60, self::ROLLING_1M_TTL);
        $this->updateRollingAverage($hostKey, '5m', $latencyMs, 300, self::ROLLING_5M_TTL);
        $this->updateRollingAverage($hostKey, '1h', $latencyMs, 3600, self::ROLLING_1H_TTL);
    }

    private function updateRollingAverage(string $hostKey, string $window, int $latencyMs, int $windowSeconds, int $ttl): void
    {
        $avgKey = self::REDIS_KEY_PREFIX . "rolling:{$hostKey}:{$window}";
        $countKey = self::REDIS_KEY_PREFIX . "rolling:{$hostKey}:{$window}:count";
        $sumKey = self::REDIS_KEY_PREFIX . "rolling:{$hostKey}:{$window}:sum";

        $currentCount = (int) ($this->redisClient->get($countKey) ?? 0);
        $currentSum = (int) ($this->redisClient->get($sumKey) ?? 0);

        $newCount = $currentCount + 1;
        $newSum = $currentSum + $latencyMs;
        $newAvg = (int) ($newSum / $newCount);

        $this->redisClient->setex($countKey, $ttl, (string) $newCount);
        $this->redisClient->setex($sumKey, $ttl, (string) $newSum);
        $this->redisClient->setex($avgKey, $ttl, (string) $newAvg);
    }

    private function parseBucketMinutes(string $bucket): int
    {
        return match ($bucket) {
            '1m' => 1,
            '5m' => 5,
            '15m' => 15,
            '1h' => 60,
            default => 5,
        };
    }

    private function getBucketKey(\DateTimeInterface $timestamp, int $bucketMinutes): string
    {
        $ts = $timestamp->getTimestamp();
        $bucketSeconds = $bucketMinutes * 60;
        $bucketTs = (int) floor($ts / $bucketSeconds) * $bucketSeconds;

        return date('Y-m-d H:i', $bucketTs);
    }

    /**
     * @param list<array{avgLatencyMs: int, requestCount: int}> $bucketData
     * @return array{avg: int, p95: int, count: int}
     */
    private function aggregateBucketData(array $bucketData): array
    {
        $totalLatency = 0;
        $totalCount = 0;
        $maxLatency = 0;

        foreach ($bucketData as $data) {
            $totalLatency += $data['avgLatencyMs'] * $data['requestCount'];
            $totalCount += $data['requestCount'];
            $maxLatency = max($maxLatency, $data['avgLatencyMs']);
        }

        return [
            'avg' => $totalCount > 0 ? (int) ($totalLatency / $totalCount) : 0,
            'p95' => $maxLatency,
            'count' => $totalCount,
        ];
    }

    private function calculateChangePercent(int $current, int $baseline): float
    {
        if ($baseline === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $baseline) / $baseline) * 100, 2);
    }
}
