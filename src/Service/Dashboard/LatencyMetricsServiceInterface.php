<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\User;

interface LatencyMetricsServiceInterface
{
    /**
     * Get latency percentiles for a host.
     *
     * @return array{p50: int, p95: int, p99: int, avg: int, min: int, max: int}
     */
    public function getPercentiles(User $user, string $host, ?\DateTimeInterface $since = null): array;

    /**
     * Get rolling averages for a host.
     *
     * @return array<string, int|null>
     */
    public function getRollingAverages(User $user, string $host): array;

    /**
     * Get latency trend for a host.
     *
     * @return 'improving'|'stable'|'degrading'
     */
    public function getTrend(User $user, string $host): string;

    /**
     * Get time-series latency data for sparklines.
     *
     * @return array<string, array{avg: int, p95: int, count: int}>
     */
    public function getLatencyTimeSeries(User $user, string $host, \DateTimeInterface $since, string $bucket = '5m'): array;

    /**
     * Get latency comparison between current period and baseline.
     *
     * @return array{
     *     current: array{p50: int, p95: int, p99: int, avg: int},
     *     baseline: array{p50: int, p95: int, p99: int, avg: int},
     *     change: array{p50: float, p95: float, p99: float, avg: float}
     * }
     */
    public function getLatencyComparison(
        User $user,
        string $host,
        \DateTimeInterface $currentStart,
        \DateTimeInterface $baselineStart,
        \DateInterval $period
    ): array;

    /**
     * Record a latency sample for rolling average calculation.
     */
    public function recordLatencySample(string $host, int $latencyMs): void;
}
