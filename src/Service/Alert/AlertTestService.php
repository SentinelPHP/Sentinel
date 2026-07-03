<?php

declare(strict_types=1);

namespace App\Service\Alert;

use App\Entity\AlertConfiguration;
use App\Enum\AlertChannelType;
use App\Http\HttpClientException;
use App\Http\HttpClientInterface;
use App\ValueObject\AlertTestResult;
use Psr\Log\LoggerInterface;

final class AlertTestService implements AlertTestServiceInterface
{
    private readonly ?object $mailer;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        ?object $mailer = null,
        private readonly string $emailFromAddress = 'sentinel@example.com',
    ) {
        $this->mailer = $mailer;
    }

    public function sendTestAlert(AlertConfiguration $config): AlertTestResult
    {
        return match ($config->getChannelType()) {
            AlertChannelType::Slack => $this->sendSlackTestAlert($config),
            AlertChannelType::Webhook => $this->sendWebhookTestAlert($config),
            AlertChannelType::Email => $this->sendEmailTestAlert($config),
        };
    }

    private function sendSlackTestAlert(AlertConfiguration $config): AlertTestResult
    {
        $channelConfig = $config->getChannelConfig();
        $webhookUrl = isset($channelConfig['webhook_url']) && is_string($channelConfig['webhook_url'])
            ? $channelConfig['webhook_url']
            : '';

        if ($webhookUrl === '') {
            return AlertTestResult::failure('Slack webhook URL is not configured.');
        }

        $payload = [
            'attachments' => [
                [
                    'color' => '#0d6efd',
                    'blocks' => [
                        [
                            'type' => 'header',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => 'Test Alert from Sentinel',
                                'emoji' => true,
                            ],
                        ],
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => 'This is a test alert to verify your Slack integration is working correctly.',
                            ],
                        ],
                        [
                            'type' => 'context',
                            'elements' => [
                                [
                                    'type' => 'mrkdwn',
                                    'text' => sprintf('Sent at: %s', (new \DateTimeImmutable())->format('Y-m-d H:i:s T')),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->httpClient->request(
                'POST',
                $webhookUrl,
                ['Content-Type' => 'application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR),
            );

            if ($response->statusCode >= 200 && $response->statusCode < 300) {
                $this->logger->info('Test Slack alert sent successfully', [
                    'config_id' => $config->getId()->toRfc4122(),
                ]);

                return AlertTestResult::success('Test alert sent to Slack successfully.');
            }

            $this->logger->error('Slack test alert failed', [
                'config_id' => $config->getId()->toRfc4122(),
                'status_code' => $response->statusCode,
                'body' => $response->body,
            ]);

            return AlertTestResult::failure(sprintf('Slack returned HTTP %d: %s', $response->statusCode, $response->body));
        } catch (HttpClientException $e) {
            $this->logger->error('Failed to send Slack test alert', [
                'config_id' => $config->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            return AlertTestResult::failure('Failed to connect to Slack: ' . $e->getMessage());
        }
    }

    private function sendWebhookTestAlert(AlertConfiguration $config): AlertTestResult
    {
        $channelConfig = $config->getChannelConfig();
        $url = isset($channelConfig['url']) && is_string($channelConfig['url'])
            ? $channelConfig['url']
            : '';

        if ($url === '') {
            return AlertTestResult::failure('Webhook URL is not configured.');
        }

        $payload = [
            'event' => 'test_alert',
            'message' => 'This is a test alert from Sentinel to verify your webhook integration.',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'config_id' => $config->getId()->toRfc4122(),
        ];

        $headers = ['Content-Type' => 'application/json'];

        $secret = isset($channelConfig['secret']) && is_string($channelConfig['secret'])
            ? $channelConfig['secret']
            : null;
        if ($secret !== null && $secret !== '') {
            $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret);
            $headers['X-Sentinel-Signature'] = $signature;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $url,
                $headers,
                json_encode($payload, JSON_THROW_ON_ERROR),
            );

            if ($response->statusCode >= 200 && $response->statusCode < 300) {
                $this->logger->info('Test webhook alert sent successfully', [
                    'config_id' => $config->getId()->toRfc4122(),
                ]);

                return AlertTestResult::success('Test alert sent to webhook successfully.');
            }

            $this->logger->error('Webhook test alert failed', [
                'config_id' => $config->getId()->toRfc4122(),
                'status_code' => $response->statusCode,
            ]);

            return AlertTestResult::failure(sprintf('Webhook returned HTTP %d', $response->statusCode));
        } catch (HttpClientException $e) {
            $this->logger->error('Failed to send webhook test alert', [
                'config_id' => $config->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            return AlertTestResult::failure('Failed to connect to webhook: ' . $e->getMessage());
        }
    }

    private function sendEmailTestAlert(AlertConfiguration $config): AlertTestResult
    {
        if ($this->mailer === null) {
            return AlertTestResult::failure('Email sending is not configured. Please configure the mailer.');
        }

        $channelConfig = $config->getChannelConfig();
        /** @var list<string> $recipients */
        $recipients = isset($channelConfig['recipients']) && is_array($channelConfig['recipients'])
            ? $channelConfig['recipients']
            : [];

        if ($recipients === []) {
            return AlertTestResult::failure('No email recipients configured.');
        }

        $subjectPrefix = isset($channelConfig['subject_prefix']) && is_string($channelConfig['subject_prefix'])
            ? $channelConfig['subject_prefix']
            : '[Sentinel Alert]';
        $subject = sprintf('%s Test Alert', $subjectPrefix);

        $body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #0d6efd; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 20px; border-radius: 0 0 8px 8px; }
        .footer { margin-top: 20px; font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0;">Test Alert from Sentinel</h1>
        </div>
        <div class="content">
            <p>This is a test alert to verify your email integration is working correctly.</p>
            <p>If you received this email, your alert configuration is set up properly.</p>
        </div>
        <div class="footer">
            <p>Sent at: {$this->formatDateTime(new \DateTimeImmutable())}</p>
        </div>
    </div>
</body>
</html>
HTML;

        if (!class_exists(\Symfony\Component\Mime\Email::class)) {
            return AlertTestResult::failure('Email sending is not configured. Please install symfony/mime.');
        }

        if (!method_exists($this->mailer, 'send')) {
            return AlertTestResult::failure('Email sending is not configured. Please install symfony/mailer.');
        }

        try {
            $emailClass = \Symfony\Component\Mime\Email::class;
            /** @var \Symfony\Component\Mime\Email $email */
            $email = new $emailClass();
            /** @var \Symfony\Component\Mime\Email $email */
            $email = $email->from($this->emailFromAddress);
            /** @var \Symfony\Component\Mime\Email $email */
            $email = $email->subject($subject);
            /** @var \Symfony\Component\Mime\Email $email */
            $email = $email->html($body);

            foreach ($recipients as $recipient) {
                /** @var \Symfony\Component\Mime\Email $email */
                $email = $email->addTo($recipient);
            }

            $this->mailer->send($email);

            $this->logger->info('Test email alert sent successfully', [
                'config_id' => $config->getId()->toRfc4122(),
                'recipients' => $recipients,
            ]);

            return AlertTestResult::success(sprintf('Test alert sent to %d recipient(s).', count($recipients)));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send email test alert', [
                'config_id' => $config->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            return AlertTestResult::failure('Failed to send email: ' . $e->getMessage());
        }
    }

    private function formatDateTime(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s T');
    }
}
