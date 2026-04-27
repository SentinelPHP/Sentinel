<?php

declare(strict_types=1);

namespace SentinelPHP\Schema\Config;

/**
 * Configuration options for JSON Schema generation.
 */
final readonly class GeneratorConfig
{
    public function __construct(
        public bool $strictMode = true,
        public bool $nullableFields = false,
        public bool $additionalProperties = false,
    ) {
    }

    /**
     * Create a strict configuration (all fields required, no additional properties).
     */
    public static function strict(): self
    {
        return new self(
            strictMode: true,
            nullableFields: false,
            additionalProperties: false,
        );
    }

    /**
     * Create a permissive configuration (no required fields, additional properties allowed).
     */
    public static function permissive(): self
    {
        return new self(
            strictMode: false,
            nullableFields: true,
            additionalProperties: true,
        );
    }
}
