<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Client;

/**
 * UUID v4 based ID generator.
 */
final class UuidIdGenerator implements IdGeneratorInterface
{
    public function generate(): string
    {
        // Generate UUID v4 without external dependencies
        $data = random_bytes(16);

        // Set version to 0100 (UUID v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10 (RFC 4122)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
