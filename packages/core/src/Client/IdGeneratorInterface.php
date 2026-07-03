<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Client;

/**
 * Interface for generating unique identifiers for API call records.
 */
interface IdGeneratorInterface
{
    /**
     * Generate a unique identifier.
     */
    public function generate(): string;
}
