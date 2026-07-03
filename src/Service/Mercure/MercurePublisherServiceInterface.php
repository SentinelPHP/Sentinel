<?php

declare(strict_types=1);

namespace App\Service\Mercure;

use App\Entity\GeneratedDto;
use App\Entity\SchemaDrift;

interface MercurePublisherServiceInterface
{
    /**
     * Publish a drift detected event to subscribed clients.
     */
    public function publishDriftDetected(SchemaDrift $drift): void;

    /**
     * Publish a service health status change event.
     */
    public function publishHealthStatusChange(string $host, string $oldStatus, string $newStatus): void;

    /**
     * Publish a request threshold exceeded event.
     */
    public function publishRequestThresholdExceeded(string $host, string $metric, float $value, float $threshold): void;

    /**
     * Publish a DTO generation completed event.
     */
    public function publishDtoGenerated(GeneratedDto $dto): void;

    /**
     * Check if Mercure publishing is available.
     */
    public function isAvailable(): bool;
}
