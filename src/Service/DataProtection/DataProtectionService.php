<?php

declare(strict_types=1);

namespace App\Service\DataProtection;

use App\Entity\ApiToken;
use App\Enum\DataProtectionStrategy;
use SentinelPHP\Encrypt\EncryptorInterface;
use SentinelPHP\Redact\PiiRedactorInterface;

final class DataProtectionService implements DataProtectionServiceInterface
{
    private DataProtectionStrategy $defaultStrategy;

    public function __construct(
        private readonly PiiRedactorInterface $piiRedactor,
        private readonly EncryptorInterface $dataEncryption,
        string $defaultStrategy = 'none',
    ) {
        $this->defaultStrategy = DataProtectionStrategy::tryFrom($defaultStrategy) ?? DataProtectionStrategy::None;
    }

    public function protect(string $data, DataProtectionStrategy $strategy, ?array $customPatterns = null): string
    {
        if ($data === '') {
            return $data;
        }

        $result = $data;

        if ($strategy->shouldRedact()) {
            $result = $this->redactData($result, $customPatterns);
        }

        if ($strategy->shouldEncrypt()) {
            if (!$this->dataEncryption->isEnabled()) {
                throw new \RuntimeException(
                    'Encryption requested but SENTINEL_ENCRYPTION_KEY is not configured'
                );
            }
            $result = $this->dataEncryption->encrypt($result);
        }

        return $result;
    }

    public function retrieve(string $data, bool $isEncrypted): string
    {
        if ($data === '') {
            return $data;
        }

        if ($isEncrypted) {
            return $this->dataEncryption->decrypt($data);
        }

        return $data;
    }

    public function getEffectiveStrategy(?ApiToken $token): DataProtectionStrategy
    {
        if ($token !== null && $token->getDataProtectionStrategy() !== null) {
            return $token->getDataProtectionStrategy();
        }

        return $this->defaultStrategy;
    }

    public function isEncryptionAvailable(): bool
    {
        return $this->dataEncryption->isEnabled();
    }

    /**
     * @param array<string, string>|null $customPatterns
     */
    private function redactData(string $data, ?array $customPatterns): string
    {
        $redacted = $this->piiRedactor->redact($data, null, $customPatterns);

        return is_string($redacted) ? $redacted : (string) json_encode($redacted);
    }
}
