<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\SchemaDrift;

final readonly class DriftDetectedEvent
{
    public function __construct(
        public SchemaDrift $drift,
    ) {
    }
}
