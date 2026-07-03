<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\User;

interface ServiceHealthServiceInterface
{
    /**
     * Get health status for all monitored services.
     *
     * @return list<array{
     *     host: string,
     *     status: string,
     *     avgLatencyMs: int,
     *     requestCount: int,
     *     errorRate: float,
     *     criticalDrifts: int,
     *     warningDrifts: int
     * }>
     */
    public function getAllServicesHealth(User $user): array;

    /**
     * Get detailed health information for a specific service.
     *
     * @return array{
     *     host: string,
     *     status: string,
     *     avgLatencyMs: int,
     *     p50LatencyMs: int,
     *     p95LatencyMs: int,
     *     p99LatencyMs: int,
     *     requestCount: int,
     *     errorRate: float,
     *     criticalDrifts: int,
     *     warningDrifts: int,
     *     infoDrifts: int,
     *     recentRequests: list<array{id: string, method: string, path: string, statusCode: int, latencyMs: int, createdAt: \DateTimeImmutable}>,
     *     recentDrifts: list<array{id: string, severity: string, path: string, createdAt: \DateTimeImmutable}>
     * }|null
     */
    public function getServiceHealthByHost(User $user, string $host): ?array;

    /**
     * Get health history for a service over the specified time period.
     *
     * @return list<array{
     *     hour: string,
     *     status: string,
     *     avgLatencyMs: int,
     *     requestCount: int,
     *     errorRate: float
     * }>
     */
    public function getHealthHistory(User $user, string $host, \DateTimeInterface $since): array;

    /**
     * Calculate health status based on metrics.
     *
     * @param array{errorRateYellow?: float, errorRateRed?: float, latencyYellow?: int, latencyRed?: int}|null $thresholds
     */
    public function calculateHealthStatus(
        float $errorRate,
        int $avgLatencyMs,
        int $criticalDrifts = 0,
        ?array $thresholds = null
    ): string;
}
