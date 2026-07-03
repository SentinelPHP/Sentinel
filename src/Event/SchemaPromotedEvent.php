<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\ApiSchema;

final readonly class SchemaPromotedEvent
{
    public function __construct(
        public ApiSchema $schema,
        public ?ApiSchema $previousMaster = null,
    ) {
    }
}
