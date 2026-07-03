<?php

declare(strict_types=1);

namespace App\Service;

interface StatusServiceInterface
{
    /**
     * @return array{uptime_seconds: int, uptime_human: string, total_requests_proxied: int, active_connections: int, timestamp: string}
     */
    public function getStatus(): array;

    public function getUptimeSeconds(): int;

    public function getTotalRequestsProxied(): int;

    public function getActiveConnections(): int;

    public function setServerStartTime(int $timestamp): void;

    public function incrementRequestCounter(): void;

    public function updateActiveConnections(int $count): void;

    public function resetStartTime(): void;
}
