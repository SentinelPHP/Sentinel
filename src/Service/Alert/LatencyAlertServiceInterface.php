<?php

declare(strict_types=1);

namespace App\Service\Alert;

interface LatencyAlertServiceInterface
{
    /**
     * Check if latency exceeds configured thresholds and dispatch alerts if needed.
     *
     * @return bool True if an alert was dispatched
     */
    public function checkAndAlert(string $host, int $latencyMs, ?string $tokenId = null): bool;

    /**
     * Get configured latency thresholds for a host.
     *
     * @return array{warning: int, critical: int}
     */
    public function getThresholds(string $host): array;

    /**
     * Check if latency exceeds warning threshold.
     */
    public function exceedsWarningThreshold(string $host, int $latencyMs): bool;

    /**
     * Check if latency exceeds critical threshold.
     */
    public function exceedsCriticalThreshold(string $host, int $latencyMs): bool;
}
