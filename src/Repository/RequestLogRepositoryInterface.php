<?php

declare(strict_types=1);

namespace App\Repository;

use Symfony\Component\Uid\Uuid;

interface RequestLogRepositoryInterface
{
    /**
     * Count requests since a given date for the specified tokens.
     *
     * @param list<Uuid> $tokenIds
     */
    public function countSince(\DateTimeInterface $since, array $tokenIds): int;

    /**
     * Get hourly request counts for trend visualization.
     *
     * @param list<Uuid> $tokenIds
     * @return array<string, int>
     */
    public function getHourlyTrend(\DateTimeInterface $since, array $tokenIds): array;

    /**
     * Get statistics per target host.
     *
     * @param list<Uuid> $tokenIds
     * @return list<array{host: string, avgLatencyMs: int|float, requestCount: int, errorRate: float}>
     */
    public function getHostStats(\DateTimeInterface $since, array $tokenIds): array;

    /**
     * Get detailed statistics for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return array{host: string, avgLatencyMs: int, p50LatencyMs: int, p95LatencyMs: int, p99LatencyMs: int, requestCount: int, errorRate: float}|null
     */
    public function getHostDetailedStats(\DateTimeInterface $since, array $tokenIds, string $host): ?array;

    /**
     * Get hourly health history for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return list<array{hour: string, avgLatencyMs: int, requestCount: int, errorRate: float}>
     */
    public function getHostHealthHistory(\DateTimeInterface $since, array $tokenIds, string $host): array;

    /**
     * Get recent requests for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return list<array{id: string, method: string, path: string, statusCode: int, latencyMs: int, createdAt: \DateTimeImmutable}>
     */
    public function getRecentRequestsByHost(array $tokenIds, string $host, int $limit = 10): array;

    /**
     * Get min/max latency for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return array{min: int, max: int}
     */
    public function getHostLatencyRange(\DateTimeInterface $since, array $tokenIds, string $host): array;

    /**
     * Get latency time series with percentiles for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return list<array{bucket: string, avgLatencyMs: int, p50LatencyMs: int, p95LatencyMs: int, requestCount: int}>
     */
    public function getHostLatencyTimeSeries(\DateTimeInterface $since, array $tokenIds, string $host, int $bucketMinutes = 5): array;
}
