<?php

declare(strict_types=1);

namespace SentinelPHP\Dto\Attribute;

/**
 * Attribute to indicate the JSON Schema format of a DTO property.
 *
 * Used by the DTO generator to preserve format hints (e.g., 'date-time', 'uuid', 'email').
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final readonly class Format
{
    public function __construct(
        public string $value,
    ) {
    }
}
