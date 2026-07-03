<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\User;
use App\Service\Dashboard\LatencyMetricsServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('LatencySparkline')]
final class LatencySparkline
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $host = '';

    #[LiveProp]
    public string $bucket = '5m';

    #[LiveProp]
    public int $hours = 1;

    #[LiveProp]
    public int $thresholdYellow = 500;

    #[LiveProp]
    public int $thresholdRed = 1000;

    #[LiveProp]
    public bool $showPercentiles = true;

    #[LiveProp]
    public int $height = 120;

    /** @var array<string, array{avg: int, p95: int, count: int}>|null */
    private ?array $cachedData = null;

    public function __construct(
        private readonly LatencyMetricsServiceInterface $latencyMetricsService,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array<string, array{avg: int, p95: int, count: int}>
     */
    public function getTimeSeriesData(): array
    {
        if ($this->cachedData !== null) {
            return $this->cachedData;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User || $this->host === '') {
            return [];
        }

        $since = new \DateTimeImmutable("-{$this->hours} hours");
        $this->cachedData = $this->latencyMetricsService->getLatencyTimeSeries(
            $user,
            $this->host,
            $since,
            $this->bucket
        );

        return $this->cachedData;
    }

    /**
     * @return array{p50: int, p95: int, p99: int, avg: int, min: int, max: int}
     */
    public function getPercentiles(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User || $this->host === '') {
            return ['p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0, 'min' => 0, 'max' => 0];
        }

        $since = new \DateTimeImmutable("-{$this->hours} hours");

        return $this->latencyMetricsService->getPercentiles($user, $this->host, $since);
    }

    /**
     * @return array<string, int|null>
     */
    public function getRollingAverages(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User || $this->host === '') {
            return ['1m' => null, '5m' => null, '1h' => null];
        }

        return $this->latencyMetricsService->getRollingAverages($user, $this->host);
    }

    /**
     * @return 'improving'|'stable'|'degrading'
     */
    public function getTrend(): string
    {
        $user = $this->security->getUser();

        if (!$user instanceof User || $this->host === '') {
            return 'stable';
        }

        return $this->latencyMetricsService->getTrend($user, $this->host);
    }

    public function getTrendIcon(): string
    {
        return match ($this->getTrend()) {
            'improving' => 'bi-arrow-down-circle text-success',
            'degrading' => 'bi-arrow-up-circle text-danger',
            default => 'bi-dash-circle text-secondary',
        };
    }

    public function getTrendLabel(): string
    {
        return match ($this->getTrend()) {
            'improving' => 'Improving',
            'degrading' => 'Degrading',
            default => 'Stable',
        };
    }

    #[LiveAction]
    public function refresh(): void
    {
        $this->cachedData = null;
    }
}
