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
use App\Redis\RedisClientInterface;
use App\Service\Alert\SlackAlertChannel;
use SentinelPHP\Redact\PiiRedactorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SlackAlertChannel::class)]
final class SlackAlertChannelTest extends TestCase
{
    private const WEBHOOK_URL = 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX';

    #[Test]
    public function itSupportsSlackChannelType(): void
    {
        $channel = $this->createChannelWithStubs();

        self::assertTrue($channel->supports('slack'));
    }

    #[Test]
    public function itDoesNotSupportOtherChannelTypes(): void
    {
        $channel = $this->createChannelWithStubs();

        self::assertFalse($channel->supports('webhook'));
        self::assertFalse($channel->supports('email'));
        self::assertFalse($channel->supports('sms'));
    }

    #[Test]
    public function itReturnsSlackAsName(): void
    {
        $channel = $this->createChannelWithStubs();

        self::assertSame('slack', $channel->getName());
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
        self::assertSame('slack', $result->channelName);
        self::assertSame('Slack webhook URL not configured', $result->errorMessage);
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
                    self::assertArrayHasKey('attachments', $payload);
                    self::assertIsArray($payload['attachments']);
                    self::assertCount(1, $payload['attachments']);
                    self::assertIsArray($payload['attachments'][0]);
                    self::assertArrayHasKey('blocks', $payload['attachments'][0]);
                    return true;
                }),
            )
            ->willReturn(new HttpResponse(200, [], 'ok'));

        $result = $channel->send($drift);

        self::assertTrue($result->isSuccess());
        self::assertSame('slack', $result->channelName);
    }

    #[Test]
    public function itReturnsFailureOnHttpError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createChannelWithMockedHttp($httpClient);
        $drift = $this->createDrift();

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn(new HttpResponse(500, [], 'Internal Server Error'));

        $result = $channel->send($drift);

        self::assertTrue($result->isFailure());
        self::assertSame('slack', $result->channelName);
        self::assertSame('Slack API error: HTTP 500', $result->errorMessage);
    }

    #[Test]
    public function itReturnsFailureOnHttpClientException(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createChannelWithMockedHttp($httpClient);
        $drift = $this->createDrift();

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->willThrowException(new HttpClientException('Connection timeout'));

        $result = $channel->send($drift);

        self::assertTrue($result->isFailure());
        self::assertSame('slack', $result->channelName);
        self::assertSame('Connection timeout', $result->errorMessage);
    }

    #[Test]
    public function itEnforcesRateLimit(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $redisClient = $this->createStub(RedisClientInterface::class);
        $redisClient->method('incr')->willReturn(11);
        $piiRedactor = $this->createPassthroughRedactor();

        $channel = new SlackAlertChannel(
            $httpClient,
            $redisClient,
            $piiRedactor,
            new NullLogger(),
            self::WEBHOOK_URL,
            10,
        );

        $drift = $this->createDrift();

        $httpClient->expects(self::never())->method('request');

        $result = $channel->send($drift);

        self::assertTrue($result->isFailure());
        self::assertSame('Rate limit exceeded', $result->errorMessage);
    }

    #[Test]
    public function itAllowsRequestsWithinRateLimit(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $redisClient = $this->createStub(RedisClientInterface::class);
        $redisClient->method('incr')->willReturn(10);
        $piiRedactor = $this->createPassthroughRedactor();

        $channel = new SlackAlertChannel(
            $httpClient,
            $redisClient,
            $piiRedactor,
            new NullLogger(),
            self::WEBHOOK_URL,
            10,
        );

        $drift = $this->createDrift();

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn(new HttpResponse(200, [], 'ok'));

        $result = $channel->send($drift);

        self::assertTrue($result->isSuccess());
    }

    #[Test]
    #[DataProvider('severityColorProvider')]
    public function itUsesCorrectColorForSeverity(DriftSeverity $severity, string $expectedColor): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createChannelWithMockedHttp($httpClient);
        $drift = $this->createDrift($severity);

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

        $payload = $this->assertAndGetPayload($capturedPayload);
        self::assertIsArray($payload['attachments']);
        $attachment = $payload['attachments'][0];
        self::assertIsArray($attachment);
        self::assertSame($expectedColor, $attachment['color']);
    }

    /**
     * @return iterable<string, array{DriftSeverity, string}>
     */
    public static function severityColorProvider(): iterable
    {
        yield 'critical' => [DriftSeverity::Critical, '#dc3545'];
        yield 'warning' => [DriftSeverity::Warning, '#fd7e14'];
        yield 'info' => [DriftSeverity::Info, '#0d6efd'];
    }

    #[Test]
    public function itIncludesEndpointInfoInMessage(): void
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

        $payload = $this->assertAndGetPayload($capturedPayload);
        $blocks = $this->getBlocks($payload);
        $endpointText = $this->getFieldText($blocks[2], 0);

        self::assertStringContainsString('GET', $endpointText);
        self::assertStringContainsString('api.example.com', $endpointText);
        self::assertStringContainsString('/users', $endpointText);
    }

    #[Test]
    public function itIncludesJsonPathInMessage(): void
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

        $payload = $this->assertAndGetPayload($capturedPayload);
        $blocks = $this->getBlocks($payload);
        $pathField = $this->getFieldText($blocks[2], 1);

        self::assertStringContainsString('$.data.user.email', $pathField);
    }

    #[Test]
    public function itIncludesExpectedAndActualValues(): void
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

        $payload = $this->assertAndGetPayload($capturedPayload);
        $blocks = $this->getBlocks($payload);
        $expectedText = $this->getFieldText($blocks[3], 0);
        $actualText = $this->getFieldText($blocks[3], 1);

        self::assertStringContainsString('Expected', $expectedText);
        self::assertStringContainsString('string', $expectedText);
        self::assertStringContainsString('Actual', $actualText);
        self::assertStringContainsString('integer', $actualText);
    }

    #[Test]
    public function itTruncatesLongValues(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createChannelWithMockedHttp($httpClient);
        $drift = $this->createDrift();
        $longValue = ['data' => str_repeat('x', 600)];
        $drift->setExpectedValue($longValue);

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

        $payload = $this->assertAndGetPayload($capturedPayload);
        $blocks = $this->getBlocks($payload);
        $expectedField = $this->getFieldText($blocks[3], 0);

        self::assertStringContainsString('...', $expectedField);
    }

    #[Test]
    public function itHandlesNullExpectedValue(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $channel = $this->createChannelWithMockedHttp($httpClient);
        $drift = $this->createDrift();
        $drift->setExpectedValue(null);

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

        $payload = $this->assertAndGetPayload($capturedPayload);
        $blocks = $this->getBlocks($payload);
        $expectedText = $this->getFieldText($blocks[3], 0);

        self::assertStringContainsString('null', $expectedText);
    }

    #[Test]
    public function itRedactsSensitiveDataInValues(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $redisClient = $this->createStub(RedisClientInterface::class);
        $redisClient->method('incr')->willReturn(1);

        $piiRedactor = $this->createMock(PiiRedactorInterface::class);
        $piiRedactor
            ->expects(self::exactly(2))
            ->method('redact')
            ->willReturnCallback(static fn (array $data) => ['type' => '[REDACTED]']);

        $channel = new SlackAlertChannel(
            $httpClient,
            $redisClient,
            $piiRedactor,
            new NullLogger(),
            self::WEBHOOK_URL,
            10,
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

        $payload = $this->assertAndGetPayload($capturedPayload);
        $blocks = $this->getBlocks($payload);
        $expectedText = $this->getFieldText($blocks[3], 0);
        $actualText = $this->getFieldText($blocks[3], 1);

        self::assertStringContainsString('[REDACTED]', $expectedText);
        self::assertStringContainsString('[REDACTED]', $actualText);
    }

    #[Test]
    public function itSetsRateLimitKeyTtlOnFirstRequest(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $redisClient = $this->createMock(RedisClientInterface::class);
        $redisClient->method('incr')->willReturn(1);
        $redisClient
            ->expects(self::once())
            ->method('setex')
            ->with(
                self::stringStartsWith('slack_alert_rate:'),
                60,
                '1',
            );
        $piiRedactor = $this->createPassthroughRedactor();

        $channel = new SlackAlertChannel(
            $httpClient,
            $redisClient,
            $piiRedactor,
            new NullLogger(),
            self::WEBHOOK_URL,
            10,
        );

        $httpClient->method('request')->willReturn(new HttpResponse(200, [], 'ok'));

        $channel->send($this->createDrift());
    }

    private function createChannelWithStubs(string $webhookUrl = self::WEBHOOK_URL): SlackAlertChannel
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $redisClient = $this->createStub(RedisClientInterface::class);
        $redisClient->method('incr')->willReturn(1);
        $piiRedactor = $this->createPassthroughRedactor();

        return new SlackAlertChannel(
            $httpClient,
            $redisClient,
            $piiRedactor,
            new NullLogger(),
            $webhookUrl,
            10,
        );
    }

    private function createChannelWithMockedHttp(HttpClientInterface&MockObject $httpClient): SlackAlertChannel
    {
        $redisClient = $this->createStub(RedisClientInterface::class);
        $redisClient->method('incr')->willReturn(1);
        $piiRedactor = $this->createPassthroughRedactor();

        return new SlackAlertChannel(
            $httpClient,
            $redisClient,
            $piiRedactor,
            new NullLogger(),
            self::WEBHOOK_URL,
            10,
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

    /**
     * @return array<string, mixed>
     */
    private function assertAndGetPayload(mixed $capturedPayload): array
    {
        self::assertIsArray($capturedPayload);
        self::assertArrayHasKey('attachments', $capturedPayload);
        self::assertIsArray($capturedPayload['attachments']);
        self::assertArrayHasKey(0, $capturedPayload['attachments']);
        self::assertIsArray($capturedPayload['attachments'][0]);

        /** @var array<string, mixed> */
        return $capturedPayload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function getBlocks(array $payload): array
    {
        self::assertArrayHasKey('attachments', $payload);
        self::assertIsArray($payload['attachments']);
        self::assertArrayHasKey(0, $payload['attachments']);
        $attachment = $payload['attachments'][0];
        self::assertIsArray($attachment);
        self::assertArrayHasKey('blocks', $attachment);
        self::assertIsArray($attachment['blocks']);

        /** @var list<array<string, mixed>> */
        return $attachment['blocks'];
    }

    /**
     * @param array<string, mixed> $block
     */
    private function getFieldText(array $block, int $fieldIndex): string
    {
        self::assertArrayHasKey('fields', $block);
        self::assertIsArray($block['fields']);
        self::assertArrayHasKey($fieldIndex, $block['fields']);
        self::assertIsArray($block['fields'][$fieldIndex]);
        self::assertArrayHasKey('text', $block['fields'][$fieldIndex]);
        $text = $block['fields'][$fieldIndex]['text'];
        self::assertIsString($text);

        return $text;
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
