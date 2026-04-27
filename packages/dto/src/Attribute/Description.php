<?php

declare(strict_types=1);

namespace SentinelPHP\Dto\Attribute;

/**
 * Attribute to add a description to a DTO property.
 *
 * Used by the DTO generator to preserve schema descriptions in generated code.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final readonly class Description
{
    public function __construct(
        public string $value,
    ) {
    }
}
