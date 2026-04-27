<?php

declare(strict_types=1);

namespace App\Event;

final readonly class ThresholdExceededEvent
{
    public function __construct(
        public string $host,
        public string $metric,
        public float $value,
        public float $threshold,
    ) {
    }
}
