<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

interface HealthStatusTrackerServiceInterface
{
    /**
     * Track health status for a host and dispatch event if changed.
     */
    public function trackHealthStatus(string $host, string $newStatus): void;

    /**
     * Track a metric threshold and dispatch event if exceeded.
     */
    public function trackThreshold(string $host, string $metric, float $value, float $threshold): void;

    /**
     * Get the current tracked status for a host.
     */
    public function getCurrentStatus(string $host): ?string;
}
