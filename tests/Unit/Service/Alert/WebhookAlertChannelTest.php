<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Alert;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\SchemaDrift;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Http\HttpClientException;
use App\Http\HttpClientInterface;
use App\Http\HttpResponse;
use App\Service\Alert\WebhookAlertChannel;
use SentinelPHP\Redact\PiiRedactorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(WebhookAlertChannel::class)]
final class WebhookAlertChannelTest extends TestCase
{
    private const WEBHOOK_URL = 'https://example.com/webhook';

    #[Test]
    public function itSupportsWebhookChannelType(): void
    {
        $channel = $this->createChannelWithStubs();

        self::assertTrue($channel->supports('webhook'));
    }

    #[Test]
    public function itDoesNotSupportOtherChannelTypes(): void
    {
        $channel = $this->createChannelWithStubs();

        self::assertFalse($channel->supports('slack'));
        self::assertFalse($channel->supports('email'));
        self::assertFalse($channel->supports('sms'));
    }

    #[Test]
    public function itReturnsWebhookAsName(): void
    {
        $channel = $this->createChannelWithStubs();

        self::assertSame('webhook', $channel->getName());
    }

    #[Test]
    public function itIsEnabledWhenWebhookUrlIsConfigured(): void
    {
        $channel = $this->createChannelWithStubs();

        self::assertTrue($channel->isEnabled());
    }

    #[Test]
    public function itIsDisabledWhenWebhookUrlIsEmpty(): void
    {
        $channel = $this->createChannelWithStubs(webhookUrl: '');

        self::assertFalse($channel->isEnabled());
    }

    #[Test]
    public function itReturnsFailureWhenWebhookUrlNotConfigured(): void
    {
        $channel = $this->createChannelWithStubs(webhookUrl: '');
        $drift = $this->createDrift();

        $result = $channel->send($drift);

        self::assertTrue($result->isFailure());
        self::assertSame('webhook', $result->channelName);
        self::assertSame('Webhook URL not configured', $result->errorMessage);
    }

    #[Test]
    public function itSendsAlertSuccessfully(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createChannelWithMockedHttp($httpClient);
        $drift = $this->createDrift();

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                self::WEBHOOK_URL,
                ['Content-Type' => 'application/json'],
                self::callback(function (string $body): bool {
                    $payload = json_decode($body, true);
                    self::assertIsArray($payload);
                    self::assertSame('schema_drift_detected', $payload['event']);
                    self::assertArrayHasKey('drift', $payload);
                    self::assertArrayHasKey('endpoint', $payload);
                    self::assertArrayHasKey('schema', $payload);
                    return true;
                }),
            )
            ->willReturn(new HttpResponse(200, [], 'ok'));

        $result = $channel->send($drift);

        self::assertTrue($result->isSuccess());
        self::assertSame('webhook', $result->channelName);
    }

    #[Test]
    public function itReturnsFailureOnClientError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createChannelWithMockedHttp($httpClient);
        $drift = $this->createDrift();

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn(new HttpResponse(400, [], 'Bad Request'));

        $result = $channel->send($drift);

        self::assertTrue($result->isFailure());
        self::assertSame('webhook', $result->channelName);
        self::assertSame('Webhook client error: HTTP 400', $result->errorMessage);
    }

    #[Test]
    public function itDoesNotRetryOnClientError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createChannelWithMockedHttp($httpClient, maxRetries: 3);
        $drift = $this->createDrift();

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn(new HttpResponse(401, [], 'Unauthorized'));

        $result = $channel->send($drift);

        self::assertTrue($result->isFailure());
        self::assertSame('Webhook client error: HTTP 401', $result->errorMessage);
    }

    #[Test]
    #[DataProvider('retryableStatusCodeProvider')]
    public function itRetriesOnServerError(int $statusCode): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createTestableChannel($httpClient, maxRetries: 2);
        $drift = $this->createDrift();

        $httpClient
            ->expects(self::exactly(3))
            ->method('request')
            ->willReturn(new HttpResponse($statusCode, [], 'Server Error'));

        $result = $channel->send($drift);

        self::assertTrue($result->isFailure());
        self::assertNotNull($result->errorMessage);
        self::assertStringContainsString('Failed after 3 attempts', $result->errorMessage);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function retryableStatusCodeProvider(): iterable
    {
        yield '500 Internal Server Error' => [500];
        yield '502 Bad Gateway' => [502];
        yield '503 Service Unavailable' => [503];
        yield '504 Gateway Timeout' => [504];
    }

    #[Test]
    public function itRetriesOnHttpClientException(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createTestableChannel($httpClient, maxRetries: 2);
        $drift = $this->createDrift();

        $httpClient
            ->expects(self::exactly(3))
            ->method('request')
            ->willThrowException(new HttpClientException('Connection timeout'));

        $result = $channel->send($drift);

        self::assertTrue($result->isFailure());
        self::assertNotNull($result->errorMessage);
        self::assertStringContainsString('Failed after 3 attempts', $result->errorMessage);
        self::assertStringContainsString('Connection timeout', $result->errorMessage);
    }

    #[Test]
    public function itSucceedsAfterRetry(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createTestableChannel($httpClient, maxRetries: 3);
        $drift = $this->createDrift();

        $httpClient
            ->expects(self::exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(503, [], 'Service Unavailable'),
                new HttpResponse(200, [], 'ok'),
            );

        $result = $channel->send($drift);

        self::assertTrue($result->isSuccess());
    }

    #[Test]
    public function itDoesNotRetryOnNonRetryableServerError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createTestableChannel($httpClient, maxRetries: 3);
        $drift = $this->createDrift();

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn(new HttpResponse(501, [], 'Not Implemented'));

        $result = $channel->send($drift);

        self::assertTrue($result->isFailure());
        self::assertSame('Webhook server error: HTTP 501', $result->errorMessage);
    }

    #[Test]
    public function itCalculatesExponentialBackoff(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $sleepTimes = [];
        $channel = $this->createTestableChannelWithSleepTracking($httpClient, $sleepTimes, maxRetries: 3, baseDelayMs: 100);
        $drift = $this->createDrift();

        $httpClient
            ->expects(self::exactly(4))
            ->method('request')
            ->willReturn(new HttpResponse(500, [], 'Server Error'));

        $channel->send($drift);

        self::assertCount(3, $sleepTimes);
        self::assertSame(100, $sleepTimes[0]);
        self::assertSame(200, $sleepTimes[1]);
        self::assertSame(400, $sleepTimes[2]);
    }

    #[Test]
    public function itIncludesDriftDataInPayload(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createChannelWithMockedHttp($httpClient);
        $drift = $this->createDrift();

        $capturedPayload = null;
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(function (string $body) use (&$capturedPayload): bool {
                    $capturedPayload = json_decode($body, true);
                    return true;
                }),
            )
            ->willReturn(new HttpResponse(200, [], 'ok'));

        $channel->send($drift);

        self::assertIsArray($capturedPayload);
        self::assertArrayHasKey('drift', $capturedPayload);
        self::assertIsArray($capturedPayload['drift']);
        $driftData = $capturedPayload['drift'];
        self::assertSame('type_changed', $driftData['type']);
        self::assertSame('warning', $driftData['severity']);
        self::assertSame('$.data.user.email', $driftData['path']);
        self::assertSame(['type' => 'string'], $driftData['expected_value']);
        self::assertSame(['type' => 'integer'], $driftData['actual_value']);
    }

    #[Test]
    public function itIncludesEndpointDataInPayload(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createChannelWithMockedHttp($httpClient);
        $drift = $this->createDrift();

        $capturedPayload = null;
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(function (string $body) use (&$capturedPayload): bool {
                    $capturedPayload = json_decode($body, true);
                    return true;
                }),
            )
            ->willReturn(new HttpResponse(200, [], 'ok'));

        $channel->send($drift);

        self::assertIsArray($capturedPayload);
        self::assertArrayHasKey('endpoint', $capturedPayload);
        self::assertIsArray($capturedPayload['endpoint']);
        $endpoint = $capturedPayload['endpoint'];
        self::assertSame('api.example.com', $endpoint['host']);
        self::assertSame('/users', $endpoint['path']);
        self::assertSame('GET', $endpoint['method']);
    }

    #[Test]
    public function itIncludesSchemaDataInPayload(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createChannelWithMockedHttp($httpClient);
        $drift = $this->createDrift();

        $capturedPayload = null;
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(function (string $body) use (&$capturedPayload): bool {
                    $capturedPayload = json_decode($body, true);
                    return true;
                }),
            )
            ->willReturn(new HttpResponse(200, [], 'ok'));

        $channel->send($drift);

        self::assertIsArray($capturedPayload);
        self::assertArrayHasKey('schema', $capturedPayload);
        self::assertIsArray($capturedPayload['schema']);
        $schema = $capturedPayload['schema'];
        self::assertSame('response', $schema['type']);
        self::assertSame(1, $schema['version']);
    }

    #[Test]
    public function itHandlesZeroMaxRetries(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createTestableChannel($httpClient, maxRetries: 0);
        $drift = $this->createDrift();

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn(new HttpResponse(500, [], 'Server Error'));

        $result = $channel->send($drift);

        self::assertTrue($result->isFailure());
        self::assertNotNull($result->errorMessage);
        self::assertStringContainsString('Failed after 1 attempts', $result->errorMessage);
    }

    #[Test]
    public function itRedactsSensitiveDataInPayload(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $piiRedactor = $this->createMock(PiiRedactorInterface::class);
        $piiRedactor
            ->expects(self::exactly(2))
            ->method('redact')
            ->willReturnCallback(static fn (array $data) => ['type' => '[REDACTED]']);

        $channel = new WebhookAlertChannel(
            $httpClient,
            $piiRedactor,
            new NullLogger(),
            self::WEBHOOK_URL,
            0,
            1000,
            static fn (int $ms) => null,
        );

        $drift = $this->createDrift();
        $drift->setExpectedValue(['email' => 'user@example.com']);
        $drift->setActualValue(['email' => 'other@example.com']);

        $capturedPayload = null;
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(function (string $body) use (&$capturedPayload): bool {
                    $capturedPayload = json_decode($body, true);
                    return true;
                }),
            )
            ->willReturn(new HttpResponse(200, [], 'ok'));

        $channel->send($drift);

        self::assertIsArray($capturedPayload);
        self::assertArrayHasKey('drift', $capturedPayload);
        self::assertIsArray($capturedPayload['drift']);
        self::assertSame(['type' => '[REDACTED]'], $capturedPayload['drift']['expected_value']);
        self::assertSame(['type' => '[REDACTED]'], $capturedPayload['drift']['actual_value']);
    }

    private function createChannelWithStubs(string $webhookUrl = self::WEBHOOK_URL): WebhookAlertChannel
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $piiRedactor = $this->createPassthroughRedactor();

        return new WebhookAlertChannel(
            $httpClient,
            $piiRedactor,
            new NullLogger(),
            $webhookUrl,
            3,
            1000,
        );
    }

    private function createChannelWithMockedHttp(
        HttpClientInterface&MockObject $httpClient,
        int $maxRetries = 3,
    ): WebhookAlertChannel {
        $piiRedactor = $this->createPassthroughRedactor();

        return new WebhookAlertChannel(
            $httpClient,
            $piiRedactor,
            new NullLogger(),
            self::WEBHOOK_URL,
            $maxRetries,
            1000,
        );
    }

    private function createTestableChannel(
        HttpClientInterface&MockObject $httpClient,
        int $maxRetries = 3,
        int $baseDelayMs = 1000,
    ): WebhookAlertChannel {
        $piiRedactor = $this->createPassthroughRedactor();

        return new WebhookAlertChannel(
            $httpClient,
            $piiRedactor,
            new NullLogger(),
            self::WEBHOOK_URL,
            $maxRetries,
            $baseDelayMs,
            static fn (int $ms) => null,
        );
    }

    /**
     * @param list<int> $sleepTimes
     */
    private function createTestableChannelWithSleepTracking(
        HttpClientInterface&MockObject $httpClient,
        array &$sleepTimes,
        int $maxRetries = 3,
        int $baseDelayMs = 1000,
    ): WebhookAlertChannel {
        $piiRedactor = $this->createPassthroughRedactor();

        return new WebhookAlertChannel(
            $httpClient,
            $piiRedactor,
            new NullLogger(),
            self::WEBHOOK_URL,
            $maxRetries,
            $baseDelayMs,
            static function (int $ms) use (&$sleepTimes): void {
                $sleepTimes[] = $ms;
            },
        );
    }

    private function createDrift(DriftSeverity $severity = DriftSeverity::Warning): SchemaDrift
    {
        $token = new ApiToken();
        $token->setName('test-token');

        $schema = new ApiSchema();
        $schema->setToken($token);
        $schema->setTargetHost('api.example.com');
        $schema->setEndpointPath('/users');
        $schema->setHttpMethod('GET');
        $schema->setSchemaType(SchemaType::Response);
        $schema->setJsonSchema(['type' => 'object']);
        $schema->setVersion(1);

        $drift = new SchemaDrift();
        $drift->setSchema($schema);
        $drift->setToken($token);
        $drift->setDriftType(DriftType::TypeChanged);
        $drift->setPath('$.data.user.email');
        $drift->setExpectedValue(['type' => 'string']);
        $drift->setActualValue(['type' => 'integer']);
        $drift->setSeverity($severity);

        return $drift;
    }

    private function createPassthroughRedactor(): PiiRedactorInterface
    {
        $redactor = $this->createStub(PiiRedactorInterface::class);
        $redactor->method('redact')->willReturnCallback(
            static fn (string|array|object $data) => $data
        );

        return $redactor;
    }
}
