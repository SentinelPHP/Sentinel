<?php

declare(strict_types=1);

namespace App\Event;

final readonly class HealthStatusChangedEvent
{
    public function __construct(
        public string $host,
        public string $oldStatus,
        public string $newStatus,
    ) {
    }
}
