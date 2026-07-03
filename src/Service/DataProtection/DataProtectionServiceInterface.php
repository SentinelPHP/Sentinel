<?php

declare(strict_types=1);

namespace App\Service\DataProtection;

use App\Entity\ApiToken;
use App\Enum\DataProtectionStrategy;

interface DataProtectionServiceInterface
{
    /**
     * Protect data using the specified strategy.
     *
     * Pipeline: Raw → Redact (if enabled) → Encrypt (if enabled) → Return
     *
     * @param string $data The raw data to protect
     * @param DataProtectionStrategy $strategy The protection strategy to apply
     * @param array<string, string>|null $customPatterns Custom redaction patterns (pattern => replacement)
     * @return string The protected data
     */
    public function protect(string $data, DataProtectionStrategy $strategy, ?array $customPatterns = null): string;

    /**
     * Retrieve protected data.
     *
     * Pipeline: Fetch → Decrypt (if encrypted) → Return (redacted data stays redacted)
     *
     * @param string $data The protected data
     * @param bool $isEncrypted Whether the data is encrypted
     * @return string The retrieved data (decrypted if was encrypted, but redaction is irreversible)
     */
    public function retrieve(string $data, bool $isEncrypted): string;

    /**
     * Get the effective data protection strategy for a token.
     *
     * Resolves token-level override vs global default.
     *
     * @param ApiToken|null $token The API token (may have strategy override)
     * @return DataProtectionStrategy The effective strategy to use
     */
    public function getEffectiveStrategy(?ApiToken $token): DataProtectionStrategy;

    /**
     * Check if encryption is available (key is configured).
     */
    public function isEncryptionAvailable(): bool;
}
