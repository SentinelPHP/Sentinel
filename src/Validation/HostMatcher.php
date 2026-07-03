<?php

declare(strict_types=1);

namespace App\Validation;

final class HostMatcher
{
    /**
     * Check if a host matches against a list of allowed patterns.
     * Supports exact matches and wildcard patterns (e.g., *.example.com).
     *
     * @param string $host The host to check
     * @param list<string> $allowedPatterns List of allowed host patterns
     * @return bool True if the host matches any pattern
     */
    public static function matches(string $host, array $allowedPatterns): bool
    {
        if ($allowedPatterns === [] || in_array('*', $allowedPatterns, true)) {
            return true;
        }

        $host = strtolower($host);

        foreach ($allowedPatterns as $pattern) {
            $pattern = strtolower($pattern);

            if ($host === $pattern) {
                return true;
            }

            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                if (str_ends_with($host, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
