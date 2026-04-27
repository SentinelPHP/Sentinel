<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\User;

interface DashboardStatsServiceInterface
{
    /**
     * Get complete overview statistics for the dashboard.
     *
     * @return array{
     *     tokens: array{total: int, active: int, inactive: int},
     *     requests: array{last24h: int, trend: array<string, int>},
     *     drifts: array{total: int, critical: int, warning: int, info: int},
     *     health: array{status: string, checks: array<string, array<string, mixed>>},
     *     recentDrifts: list<array{id: string, severity: string, endpoint: string, createdAt: \DateTimeImmutable}>,
     *     services: list<array{host: string, status: string, avgLatencyMs: int, requestCount: int}>
     * }
     */
    public function getOverviewStats(User $user): array;

    /**
     * Get token statistics for accessible tokens.
     *
     * @return array{total: int, active: int, inactive: int}
     */
    public function getTokenStats(User $user): array;

    /**
     * Get request statistics for the given time period.
     *
     * @return array{last24h: int, trend: array<string, int>}
     */
    public function getRequestStats(User $user, \DateTimeInterface $since): array;

    /**
     * Get drift statistics grouped by severity.
     *
     * @return array{total: int, critical: int, warning: int, info: int}
     */
    public function getDriftStats(User $user, \DateTimeInterface $since): array;

    /**
     * Get recent drifts for display.
     *
     * @return list<array{id: string, severity: string, endpoint: string, createdAt: \DateTimeImmutable}>
     */
    public function getRecentDrifts(User $user, int $limit = 5): array;

    /**
     * Get service health summary per target host.
     *
     * @return list<array{host: string, status: string, avgLatencyMs: int, requestCount: int}>
     */
    public function getServiceHealthSummary(User $user): array;
}
