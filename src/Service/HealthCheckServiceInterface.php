<?php

declare(strict_types=1);

namespace App\Service;

interface HealthCheckServiceInterface
{
    /**
     * @return array{status: string, timestamp: string, checks: array<string, array<string, mixed>>}
     */
    public function getHealthStatus(): array;

    /**
     * @return array{status: string, latency_ms?: int, message?: string}
     */
    public function checkDatabase(): array;

    /**
     * @return array{status: string, latency_ms?: int, message?: string}
     */
    public function checkRedis(): array;

    /**
     * @return array{status: string, latency_ms?: int, url?: string, message?: string}
     */
    public function checkOutbound(): array;
}
