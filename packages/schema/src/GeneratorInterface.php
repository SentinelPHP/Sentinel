<?php

declare(strict_types=1);

namespace SentinelPHP\Schema;

use SentinelPHP\Schema\Config\GeneratorConfig;

interface GeneratorInterface
{
    /**
     * Generate a JSON Schema from a JSON payload.
     *
     * @param array<string, mixed>|list<mixed> $payload The JSON payload to generate schema from
     * @param GeneratorConfig|null $config Configuration options for schema generation (defaults to strict mode)
     * @return array<string, mixed> The generated JSON Schema (Draft 2020-12)
     */
    public function generate(array $payload, ?GeneratorConfig $config = null): array;
}
