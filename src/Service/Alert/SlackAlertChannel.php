<?php

declare(strict_types=1);

namespace App\Service\Alert;

use App\Entity\SchemaDrift;
use SentinelPHP\Drift\Enum\DriftSeverity;
use App\Http\HttpClientException;
use App\Http\HttpClientInterface;
use App\Redis\RedisClientInterface;
use SentinelPHP\Redact\PiiRedactorInterface;
use App\ValueObject\AlertResult;
use Psr\Log\LoggerInterface;

final class SlackAlertChannel implements AlertChannelInterface
{
    private const CHANNEL_NAME = 'slack';
    private const RATE_LIMIT_KEY_PREFIX = 'slack_alert_rate:';
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RedisClientInterface $redisClient,
        private readonly PiiRedactorInterface $piiRedactor,
        private readonly LoggerInterface $logger,
        private readonly string $webhookUrl,
        private readonly int $maxAlertsPerMinute = 10,
    ) {
    }

    public function send(SchemaDrift $drift): AlertResult
    {
        if ($this->webhookUrl === '') {
            return AlertResult::failure(self::CHANNEL_NAME, 'Slack webhook URL not configured');
        }

        if ($this->isRateLimited()) {
            $this->logger->warning('Slack alert rate limited', [
                'drift_id' => $drift->getId()->toRfc4122(),
                'max_per_minute' => $this->maxAlertsPerMinute,
            ]);

            return AlertResult::failure(self::CHANNEL_NAME, 'Rate limit exceeded');
        }

        $payload = $this->buildPayload($drift);

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->webhookUrl,
                ['Content-Type' => 'application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR),
            );

            if ($response->statusCode >= 200 && $response->statusCode < 300) {
                $this->logger->info('Slack alert sent successfully', [
                    'drift_id' => $drift->getId()->toRfc4122(),
                ]);

                return AlertResult::success(self::CHANNEL_NAME);
            }

            $this->logger->error('Slack webhook returned error', [
                'drift_id' => $drift->getId()->toRfc4122(),
                'status_code' => $response->statusCode,
                'body' => $response->body,
            ]);

            return AlertResult::failure(
                self::CHANNEL_NAME,
                sprintf('Slack API error: HTTP %d', $response->statusCode),
            );
        } catch (HttpClientException $e) {
            $this->logger->error('Failed to send Slack alert', [
                'drift_id' => $drift->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            return AlertResult::failure(self::CHANNEL_NAME, $e->getMessage());
        }
    }

    public function supports(string $channelType): bool
    {
        return $channelType === self::CHANNEL_NAME;
    }

    public function getName(): string
    {
        return self::CHANNEL_NAME;
    }

    public function isEnabled(): bool
    {
        return $this->webhookUrl !== '';
    }

    private function isRateLimited(): bool
    {
        $key = self::RATE_LIMIT_KEY_PREFIX . $this->getCurrentMinuteKey();
        $count = $this->redisClient->incr($key);

        if ($count === 1) {
            $this->redisClient->setex($key, self::RATE_LIMIT_WINDOW_SECONDS, '1');
        }

        return $count > $this->maxAlertsPerMinute;
    }

    private function getCurrentMinuteKey(): string
    {
        return (string) floor(time() / self::RATE_LIMIT_WINDOW_SECONDS);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(SchemaDrift $drift): array
    {
        $schema = $drift->getSchema();
        $severity = $drift->getSeverity();

        return [
            'attachments' => [
                [
                    'color' => $this->getSeverityColor($severity),
                    'blocks' => $this->buildBlocks($drift),
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBlocks(SchemaDrift $drift): array
    {
        $schema = $drift->getSchema();
        $severity = $drift->getSeverity();

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => sprintf('%s Schema Drift Detected', $this->getSeverityEmoji($severity)),
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => sprintf('*Severity:*\n%s', ucfirst($severity->value)),
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => sprintf('*Drift Type:*\n%s', $this->formatDriftType($drift->getDriftType()->value)),
                    ],
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => sprintf(
                            '*Endpoint:*\n`%s %s%s`',
                            $schema->getHttpMethod(),
                            $schema->getTargetHost(),
                            $schema->getEndpointPath(),
                        ),
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => sprintf('*JSON Path:*\n`%s`', $drift->getPath()),
                    ],
                ],
            ],
        ];

        $expectedValue = $drift->getExpectedValue();
        $actualValue = $drift->getActualValue();

        if ($expectedValue !== null || $actualValue !== null) {
            $redactedExpected = $expectedValue !== null ? $this->redactValue($expectedValue) : null;
            $redactedActual = $actualValue !== null ? $this->redactValue($actualValue) : null;

            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => sprintf('*Expected:*\n```%s```', $this->formatValue($redactedExpected)),
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => sprintf('*Actual:*\n```%s```', $this->formatValue($redactedActual)),
                    ],
                ],
            ];
        }

        $blocks[] = [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        'Drift ID: `%s` | Detected at: %s',
                        $drift->getId()->toRfc4122(),
                        $drift->getCreatedAt()->format('Y-m-d H:i:s T'),
                    ),
                ],
            ],
        ];

        return $blocks;
    }

    private function getSeverityColor(DriftSeverity $severity): string
    {
        return match ($severity) {
            DriftSeverity::Critical => '#dc3545',
            DriftSeverity::Warning => '#fd7e14',
            DriftSeverity::Info => '#0d6efd',
        };
    }

    private function getSeverityEmoji(DriftSeverity $severity): string
    {
        return match ($severity) {
            DriftSeverity::Critical => '🚨',
            DriftSeverity::Warning => '⚠️',
            DriftSeverity::Info => 'ℹ️',
        };
    }

    private function formatDriftType(string $driftType): string
    {
        return ucwords(str_replace('_', ' ', $driftType));
    }

    /**
     * @param array<string, mixed>|null $value
     */
    private function formatValue(?array $value): string
    {
        if ($value === null) {
            return 'null';
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return '(unable to encode)';
        }

        if (strlen($json) > 500) {
            return substr($json, 0, 497) . '...';
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function redactValue(array $value): array
    {
        /** @var array<string, mixed> */
        $redacted = $this->piiRedactor->redact($value);

        return $redacted;
    }
}
