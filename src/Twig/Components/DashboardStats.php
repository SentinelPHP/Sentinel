<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\User;
use App\Service\Dashboard\DashboardStatsServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('DashboardStats')]
final class DashboardStats
{
    use DefaultActionTrait;

    /** @var array<string, mixed>|null */
    private ?array $cachedStats = null;

    public function __construct(
        private readonly DashboardStatsServiceInterface $dashboardStatsService,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array{
     *     tokens: array{total: int, active: int, inactive: int},
     *     requests: array{last24h: int, trend: array<string, int>},
     *     drifts: array{total: int, critical: int, warning: int, info: int},
     *     health: array{status: string, checks: array<string, array<string, mixed>>},
     *     recentDrifts: list<array{id: string, severity: string, endpoint: string, createdAt: \DateTimeImmutable}>,
     *     services: list<array{host: string, status: string, avgLatencyMs: int, requestCount: int}>
     * }
     */
    public function getStats(): array
    {
        if ($this->cachedStats !== null) {
            /** @var array{tokens: array{total: int, active: int, inactive: int}, requests: array{last24h: int, trend: array<string, int>}, drifts: array{total: int, critical: int, warning: int, info: int}, health: array{status: string, checks: array<string, array<string, mixed>>}, recentDrifts: list<array{id: string, severity: string, endpoint: string, createdAt: \DateTimeImmutable}>, services: list<array{host: string, status: string, avgLatencyMs: int, requestCount: int}>} */
            return $this->cachedStats;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return $this->getEmptyStats();
        }

        $stats = $this->dashboardStatsService->getOverviewStats($user);
        $this->cachedStats = $stats;

        return $stats;
    }

    #[LiveAction]
    public function refresh(): void
    {
        $this->cachedStats = null;
    }

    /**
     * @return array{
     *     tokens: array{total: int, active: int, inactive: int},
     *     requests: array{last24h: int, trend: array<string, int>},
     *     drifts: array{total: int, critical: int, warning: int, info: int},
     *     health: array{status: string, checks: array<string, array<string, mixed>>},
     *     recentDrifts: list<array{id: string, severity: string, endpoint: string, createdAt: \DateTimeImmutable}>,
     *     services: list<array{host: string, status: string, avgLatencyMs: int, requestCount: int}>
     * }
     */
    private function getEmptyStats(): array
    {
        return [
            'tokens' => ['total' => 0, 'active' => 0, 'inactive' => 0],
            'requests' => ['last24h' => 0, 'trend' => []],
            'drifts' => ['total' => 0, 'critical' => 0, 'warning' => 0, 'info' => 0],
            'health' => ['status' => 'unknown', 'checks' => []],
            'recentDrifts' => [],
            'services' => [],
        ];
    }
}
