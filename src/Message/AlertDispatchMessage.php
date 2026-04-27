<?php

declare(strict_types=1);

namespace App\Message;

final readonly class AlertDispatchMessage
{
    public function __construct(
        public string $driftId,
    ) {
    }
}
