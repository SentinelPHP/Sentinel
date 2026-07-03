<?php

declare(strict_types=1);

namespace SentinelPHP\Redact;

interface PiiRedactorInterface
{
    /**
     * Redact PII from a string value.
     *
     * @param string $value The string to redact
     * @param array<string, string>|null $customPatterns Additional patterns (pattern => replacement)
     * @return string The redacted string
     */
    public function redactString(string $value, ?array $customPatterns = null): string;

    /**
     * Redact PII from a JSON string or decoded array/object.
     *
     * @param string|array<mixed>|object $data JSON string or decoded data
     * @param list<string>|null $fieldPaths JSON paths to always redact (e.g., ['$.password', '$.secret'])
     * @param array<string, string>|null $customPatterns Additional patterns (pattern => replacement)
     * @return string|array<mixed>|object The redacted data (same type as input)
     */
    public function redact(string|array|object $data, ?array $fieldPaths = null, ?array $customPatterns = null): string|array|object;

    /**
     * Add a custom redaction pattern.
     *
     * @param string $name Unique name for the pattern
     * @param string $pattern Regex pattern to match
     * @param string $replacement Replacement string (can use backreferences)
     */
    public function addPattern(string $name, string $pattern, string $replacement): void;

    /**
     * Remove a redaction pattern by name.
     */
    public function removePattern(string $name): void;

    /**
     * Get all registered pattern names.
     *
     * @return list<string>
     */
    public function getPatternNames(): array;
}
