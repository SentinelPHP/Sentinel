<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Alert;

use App\Entity\AlertConfiguration;
use App\Enum\AlertChannelType;
use App\Http\HttpClientException;
use App\Http\HttpClientInterface;
use App\Http\HttpResponse;
use App\Service\Alert\AlertTestService;
use App\ValueObject\AlertTestResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AlertTestService::class)]
#[AllowMockObjectsWithoutExpectations]
final class AlertTestServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createService(?object $mailer = null): AlertTestService
    {
        return new AlertTestService(
            $this->httpClient,
            $this->logger,
            $mailer,
        );
    }

    /**
     * @param array<string, mixed> $channelConfig
     */
    private function createConfig(AlertChannelType $type, array $channelConfig): AlertConfiguration
    {
        $config = $this->createMock(AlertConfiguration::class);
        $config->method('getId')->willReturn(Uuid::v4());
        $config->method('getChannelType')->willReturn($type);
        $config->method('getChannelConfig')->willReturn($channelConfig);

        return $config;
    }

    #[Test]
    public function sendSlackTestAlertSucceeds(): void
    {
        $config = $this->createConfig(AlertChannelType::Slack, [
            'webhook_url' => 'https://hooks.slack.com/services/xxx',
        ]);

        $this->httpClient->method('request')
            ->willReturn(new HttpResponse(200, [], 'ok'));

        $service = $this->createService();
        $result = $service->sendTestAlert($config);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('Slack', $result->getMessage());
    }

    #[Test]
    public function sendSlackTestAlertFailsWithMissingWebhookUrl(): void
    {
        $config = $this->createConfig(AlertChannelType::Slack, []);

        $service = $this->createService();
        $result = $service->sendTestAlert($config);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('not configured', $result->getMessage());
    }

    #[Test]
    public function sendSlackTestAlertFailsWithHttpError(): void
    {
        $config = $this->createConfig(AlertChannelType::Slack, [
            'webhook_url' => 'https://hooks.slack.com/services/xxx',
        ]);

        $this->httpClient->method('request')
            ->willReturn(new HttpResponse(400, [], 'invalid_payload'));

        $service = $this->createService();
        $result = $service->sendTestAlert($config);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('400', $result->getMessage());
    }

    #[Test]
    public function sendSlackTestAlertFailsWithConnectionError(): void
    {
        $config = $this->createConfig(AlertChannelType::Slack, [
            'webhook_url' => 'https://hooks.slack.com/services/xxx',
        ]);

        $this->httpClient->method('request')
            ->willThrowException(new HttpClientException('Connection refused'));

        $service = $this->createService();
        $result = $service->sendTestAlert($config);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('Connection refused', $result->getMessage());
    }

    #[Test]
    public function sendWebhookTestAlertSucceeds(): void
    {
        $config = $this->createConfig(AlertChannelType::Webhook, [
            'url' => 'https://example.com/webhook',
        ]);

        $this->httpClient->method('request')
            ->willReturn(new HttpResponse(200, [], ''));

        $service = $this->createService();
        $result = $service->sendTestAlert($config);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('webhook', $result->getMessage());
    }

    #[Test]
    public function sendWebhookTestAlertFailsWithMissingUrl(): void
    {
        $config = $this->createConfig(AlertChannelType::Webhook, []);

        $service = $this->createService();
        $result = $service->sendTestAlert($config);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('not configured', $result->getMessage());
    }

    #[Test]
    public function sendWebhookTestAlertIncludesSignatureWhenSecretConfigured(): void
    {
        $config = $this->createConfig(AlertChannelType::Webhook, [
            'url' => 'https://example.com/webhook',
            'secret' => 'my-secret-key',
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://example.com/webhook',
                $this->callback(function (array $headers): bool {
                    return isset($headers['X-Sentinel-Signature'])
                        && \is_string($headers['X-Sentinel-Signature'])
                        && strlen($headers['X-Sentinel-Signature']) === 64;
                }),
                $this->anything()
            )
            ->willReturn(new HttpResponse(200, [], ''));

        $service = $this->createService();
        $service->sendTestAlert($config);
    }

    #[Test]
    public function sendWebhookTestAlertFailsWithHttpError(): void
    {
        $config = $this->createConfig(AlertChannelType::Webhook, [
            'url' => 'https://example.com/webhook',
        ]);

        $this->httpClient->method('request')
            ->willReturn(new HttpResponse(500, [], ''));

        $service = $this->createService();
        $result = $service->sendTestAlert($config);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('500', $result->getMessage());
    }

    #[Test]
    public function sendEmailTestAlertFailsWithoutMailer(): void
    {
        $config = $this->createConfig(AlertChannelType::Email, [
            'recipients' => ['test@example.com'],
        ]);

        $service = $this->createService(null);
        $result = $service->sendTestAlert($config);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('not configured', $result->getMessage());
    }

    #[Test]
    public function sendEmailTestAlertFailsWithNoRecipients(): void
    {
        $mailer = new class {
            public function send(object $email): void {}
        };

        $config = $this->createConfig(AlertChannelType::Email, [
            'recipients' => [],
        ]);

        $service = $this->createService($mailer);
        $result = $service->sendTestAlert($config);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('No email recipients', $result->getMessage());
    }

    #[Test]
    public function sendEmailTestAlertFailsWhenMailerHasNoSendMethod(): void
    {
        $mailer = new \stdClass();

        $config = $this->createConfig(AlertChannelType::Email, [
            'recipients' => ['test@example.com'],
        ]);

        $service = $this->createService($mailer);
        $result = $service->sendTestAlert($config);

        self::assertFalse($result->isSuccess());
    }
}
