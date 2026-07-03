<?php

declare(strict_types=1);

namespace App\Service\Alert;

use App\Entity\SchemaDrift;
use App\Http\HttpClientException;
use App\Http\HttpClientInterface;
use SentinelPHP\Redact\PiiRedactorInterface;
use App\ValueObject\AlertResult;
use Psr\Log\LoggerInterface;

final class WebhookAlertChannel implements AlertChannelInterface
{
    private const CHANNEL_NAME = 'webhook';
    private const DEFAULT_MAX_RETRIES = 3;
    private const DEFAULT_BASE_DELAY_MS = 1000;
    private const RETRYABLE_STATUS_CODES = [500, 502, 503, 504];

    /** @var \Closure(int): void */
    private \Closure $sleepFn;

    /**
     * @param (\Closure(int): void)|null $sleepFn Custom sleep function for testing (receives milliseconds)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PiiRedactorInterface $piiRedactor,
        private readonly LoggerInterface $logger,
        private readonly string $webhookUrl,
        private readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        private readonly int $baseDelayMs = self::DEFAULT_BASE_DELAY_MS,
        ?\Closure $sleepFn = null,
        private readonly ?CircuitBreaker $circuitBreaker = null,
    ) {
        $this->sleepFn = $sleepFn ?? static fn (int $ms) => usleep($ms * 1000);
    }

    public function send(SchemaDrift $drift): AlertResult
    {
        if ($this->webhookUrl === '') {
            return AlertResult::failure(self::CHANNEL_NAME, 'Webhook URL not configured');
        }

        if ($this->circuitBreaker !== null && !$this->circuitBreaker->isAvailable(self::CHANNEL_NAME)) {
            $this->logger->warning('Webhook circuit breaker is open, skipping request', [
                'drift_id' => $drift->getId()->toRfc4122(),
            ]);

            return AlertResult::failure(self::CHANNEL_NAME, 'Circuit breaker is open');
        }

        $payload = $this->buildPayload($drift);

        $lastError = '';
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                ($this->sleepFn)($this->calculateDelayMs($attempt));
            }

            try {
                $response = $this->httpClient->request(
                    'POST',
                    $this->webhookUrl,
                    ['Content-Type' => 'application/json'],
                    json_encode($payload, JSON_THROW_ON_ERROR),
                );

                if ($response->statusCode >= 200 && $response->statusCode < 300) {
                    $this->circuitBreaker?->recordSuccess(self::CHANNEL_NAME);

                    $this->logger->info('Webhook alert sent successfully', [
                        'drift_id' => $drift->getId()->toRfc4122(),
                        'attempt' => $attempt + 1,
                    ]);

                    return AlertResult::success(self::CHANNEL_NAME);
                }

                if ($response->statusCode >= 400 && $response->statusCode < 500) {
                    $this->logger->error('Webhook returned client error, not retrying', [
                        'drift_id' => $drift->getId()->toRfc4122(),
                        'status_code' => $response->statusCode,
                        'body' => $response->body,
                    ]);

                    return AlertResult::failure(
                        self::CHANNEL_NAME,
                        sprintf('Webhook client error: HTTP %d', $response->statusCode),
                    );
                }

                $lastError = sprintf('Webhook server error: HTTP %d', $response->statusCode);

                if (!$this->isRetryableStatusCode($response->statusCode)) {
                    $this->logger->error('Webhook returned non-retryable error', [
                        'drift_id' => $drift->getId()->toRfc4122(),
                        'status_code' => $response->statusCode,
                    ]);

                    return AlertResult::failure(self::CHANNEL_NAME, $lastError);
                }

                $this->logger->warning('Webhook request failed, will retry', [
                    'drift_id' => $drift->getId()->toRfc4122(),
                    'status_code' => $response->statusCode,
                    'attempt' => $attempt + 1,
                    'max_retries' => $this->maxRetries,
                ]);
            } catch (HttpClientException $e) {
                $lastError = $e->getMessage();

                $this->logger->warning('Webhook request exception, will retry', [
                    'drift_id' => $drift->getId()->toRfc4122(),
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                    'max_retries' => $this->maxRetries,
                ]);
            }
        }

        $this->circuitBreaker?->recordFailure(self::CHANNEL_NAME);

        $this->logger->error('Webhook alert failed after all retries', [
            'drift_id' => $drift->getId()->toRfc4122(),
            'total_attempts' => $this->maxRetries + 1,
            'last_error' => $lastError,
        ]);

        return AlertResult::failure(
            self::CHANNEL_NAME,
            sprintf('Failed after %d attempts: %s', $this->maxRetries + 1, $lastError),
        );
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

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(SchemaDrift $drift): array
    {
        $schema = $drift->getSchema();

        $expectedValue = $drift->getExpectedValue();
        $actualValue = $drift->getActualValue();

        return [
            'event' => 'schema_drift_detected',
            'drift' => [
                'id' => $drift->getId()->toRfc4122(),
                'type' => $drift->getDriftType()->value,
                'severity' => $drift->getSeverity()->value,
                'path' => $drift->getPath(),
                'expected_value' => $expectedValue !== null ? $this->redactValue($expectedValue) : null,
                'actual_value' => $actualValue !== null ? $this->redactValue($actualValue) : null,
                'created_at' => $drift->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'endpoint' => [
                'host' => $schema->getTargetHost(),
                'path' => $schema->getEndpointPath(),
                'method' => $schema->getHttpMethod(),
            ],
            'schema' => [
                'id' => $schema->getId()->toRfc4122(),
                'type' => $schema->getSchemaType()->value,
                'version' => $schema->getVersion(),
            ],
        ];
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

    private function calculateDelayMs(int $attempt): int
    {
        return $this->baseDelayMs * (2 ** ($attempt - 1));
    }

    private function isRetryableStatusCode(int $statusCode): bool
    {
        return in_array($statusCode, self::RETRYABLE_STATUS_CODES, true);
    }
}
