<?php

declare(strict_types=1);

namespace App\Service\Alert;

use App\Redis\RedisClientInterface;
use App\Repository\AlertConfigurationRepository;
use Psr\Log\LoggerInterface;

final class LatencyAlertService implements LatencyAlertServiceInterface
{
    private const DEFAULT_WARNING_THRESHOLD = 500;
    private const DEFAULT_CRITICAL_THRESHOLD = 1000;
    private const ALERT_COOLDOWN_SECONDS = 300;
    private const REDIS_KEY_PREFIX = 'latency_alert:';

    public function __construct(
        private readonly AlertConfigurationRepository $alertConfigRepository,
        private readonly RedisClientInterface $redisClient,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $defaultWarningThreshold = self::DEFAULT_WARNING_THRESHOLD,
        private readonly int $defaultCriticalThreshold = self::DEFAULT_CRITICAL_THRESHOLD,
    ) {
    }

    public function checkAndAlert(string $host, int $latencyMs, ?string $tokenId = null): bool
    {
        $thresholds = $this->getThresholds($host);

        if ($latencyMs < $thresholds['warning']) {
            return false;
        }

        if ($this->isInCooldown($host)) {
            $this->logger?->debug('Latency alert skipped due to cooldown', [
                'host' => $host,
                'latencyMs' => $latencyMs,
            ]);
            return false;
        }

        $severity = $latencyMs >= $thresholds['critical'] ? 'critical' : 'warning';

        $this->logger?->warning('Latency threshold exceeded', [
            'host' => $host,
            'latencyMs' => $latencyMs,
            'severity' => $severity,
            'threshold' => $severity === 'critical' ? $thresholds['critical'] : $thresholds['warning'],
            'tokenId' => $tokenId,
        ]);

        $this->setCooldown($host);

        return true;
    }

    public function getThresholds(string $host): array
    {
        $config = $this->alertConfigRepository->findLatencyThresholdsForHost($host);

        if ($config !== null) {
            $channelConfig = $config->getChannelConfig();
            $warning = $channelConfig['latencyWarning'] ?? null;
            $critical = $channelConfig['latencyCritical'] ?? null;

            return [
                'warning' => is_numeric($warning) ? (int) $warning : $this->defaultWarningThreshold,
                'critical' => is_numeric($critical) ? (int) $critical : $this->defaultCriticalThreshold,
            ];
        }

        return [
            'warning' => $this->defaultWarningThreshold,
            'critical' => $this->defaultCriticalThreshold,
        ];
    }

    public function exceedsWarningThreshold(string $host, int $latencyMs): bool
    {
        $thresholds = $this->getThresholds($host);

        return $latencyMs >= $thresholds['warning'];
    }

    public function exceedsCriticalThreshold(string $host, int $latencyMs): bool
    {
        $thresholds = $this->getThresholds($host);

        return $latencyMs >= $thresholds['critical'];
    }

    private function isInCooldown(string $host): bool
    {
        $key = $this->getCooldownKey($host);

        return $this->redisClient->get($key) !== null;
    }

    private function setCooldown(string $host): void
    {
        $key = $this->getCooldownKey($host);
        $this->redisClient->setex($key, self::ALERT_COOLDOWN_SECONDS, '1');
    }

    private function getCooldownKey(string $host): string
    {
        $normalizedHost = preg_replace('/[^a-zA-Z0-9._-]/', '_', $host) ?? $host;

        return self::REDIS_KEY_PREFIX . "cooldown:{$normalizedHost}";
    }
}
