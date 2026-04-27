<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AlertConfiguration;
use App\Entity\ApiToken;
use App\Enum\AlertChannelType;
use SentinelPHP\Drift\Enum\DriftSeverity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AlertConfiguration::class)]
final class AlertConfigurationTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $config = new AlertConfiguration();

        self::assertInstanceOf(Uuid::class, $config->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $config->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $config->getUpdatedAt());
        self::assertNull($config->getToken());
        self::assertTrue($config->isActive());
        self::assertSame([], $config->getChannelConfig());
    }

    #[Test]
    public function settersAndGettersWork(): void
    {
        $config = new AlertConfiguration();
        $token = new ApiToken();
        $channelConfig = ['webhook_url' => 'https://example.com/webhook'];

        $config->setToken($token);
        $config->setChannelType(AlertChannelType::Webhook);
        $config->setChannelConfig($channelConfig);
        $config->setMinSeverity(DriftSeverity::Warning);
        $config->setIsActive(false);

        self::assertSame($token, $config->getToken());
        self::assertSame(AlertChannelType::Webhook, $config->getChannelType());
        self::assertSame($channelConfig, $config->getChannelConfig());
        self::assertSame(DriftSeverity::Warning, $config->getMinSeverity());
        self::assertFalse($config->isActive());
    }

    #[Test]
    public function tokenCanBeSetToNull(): void
    {
        $config = new AlertConfiguration();
        $token = new ApiToken();

        $config->setToken($token);
        self::assertSame($token, $config->getToken());

        $config->setToken(null);
        self::assertNull($config->getToken());
    }

    #[Test]
    public function isGlobalReturnsTrueWhenTokenIsNull(): void
    {
        $config = new AlertConfiguration();

        self::assertTrue($config->isGlobal());
    }

    #[Test]
    public function isGlobalReturnsFalseWhenTokenIsSet(): void
    {
        $config = new AlertConfiguration();
        $token = new ApiToken();

        $config->setToken($token);

        self::assertFalse($config->isGlobal());
    }

    #[Test]
    public function channelTypeCanBeSetToSlack(): void
    {
        $config = new AlertConfiguration();

        $config->setChannelType(AlertChannelType::Slack);

        self::assertSame(AlertChannelType::Slack, $config->getChannelType());
    }

    #[Test]
    public function channelTypeCanBeSetToWebhook(): void
    {
        $config = new AlertConfiguration();

        $config->setChannelType(AlertChannelType::Webhook);

        self::assertSame(AlertChannelType::Webhook, $config->getChannelType());
    }

    #[Test]
    public function channelTypeCanBeSetToEmail(): void
    {
        $config = new AlertConfiguration();

        $config->setChannelType(AlertChannelType::Email);

        self::assertSame(AlertChannelType::Email, $config->getChannelType());
    }

    #[Test]
    public function minSeverityCanBeSetToInfo(): void
    {
        $config = new AlertConfiguration();

        $config->setMinSeverity(DriftSeverity::Info);

        self::assertSame(DriftSeverity::Info, $config->getMinSeverity());
    }

    #[Test]
    public function minSeverityCanBeSetToWarning(): void
    {
        $config = new AlertConfiguration();

        $config->setMinSeverity(DriftSeverity::Warning);

        self::assertSame(DriftSeverity::Warning, $config->getMinSeverity());
    }

    #[Test]
    public function minSeverityCanBeSetToCritical(): void
    {
        $config = new AlertConfiguration();

        $config->setMinSeverity(DriftSeverity::Critical);

        self::assertSame(DriftSeverity::Critical, $config->getMinSeverity());
    }

    #[Test]
    #[DataProvider('shouldAlertForProvider')]
    public function shouldAlertForRespectsMinSeverity(
        DriftSeverity $minSeverity,
        DriftSeverity $driftSeverity,
        bool $expected,
    ): void {
        $config = new AlertConfiguration();
        $config->setMinSeverity($minSeverity);
        $config->setChannelType(AlertChannelType::Slack);
        $config->setIsActive(true);

        self::assertSame($expected, $config->shouldAlertFor($driftSeverity));
    }

    /**
     * @return iterable<string, array{DriftSeverity, DriftSeverity, bool}>
     */
    public static function shouldAlertForProvider(): iterable
    {
        yield 'info min, info drift' => [DriftSeverity::Info, DriftSeverity::Info, true];
        yield 'info min, warning drift' => [DriftSeverity::Info, DriftSeverity::Warning, true];
        yield 'info min, critical drift' => [DriftSeverity::Info, DriftSeverity::Critical, true];

        yield 'warning min, info drift' => [DriftSeverity::Warning, DriftSeverity::Info, false];
        yield 'warning min, warning drift' => [DriftSeverity::Warning, DriftSeverity::Warning, true];
        yield 'warning min, critical drift' => [DriftSeverity::Warning, DriftSeverity::Critical, true];

        yield 'critical min, info drift' => [DriftSeverity::Critical, DriftSeverity::Info, false];
        yield 'critical min, warning drift' => [DriftSeverity::Critical, DriftSeverity::Warning, false];
        yield 'critical min, critical drift' => [DriftSeverity::Critical, DriftSeverity::Critical, true];
    }

    #[Test]
    public function shouldAlertForReturnsFalseWhenInactive(): void
    {
        $config = new AlertConfiguration();
        $config->setMinSeverity(DriftSeverity::Info);
        $config->setChannelType(AlertChannelType::Slack);
        $config->setIsActive(false);

        self::assertFalse($config->shouldAlertFor(DriftSeverity::Critical));
    }

    #[Test]
    public function fluentSettersReturnSelf(): void
    {
        $config = new AlertConfiguration();
        $token = new ApiToken();

        self::assertSame($config, $config->setToken($token));
        self::assertSame($config, $config->setChannelType(AlertChannelType::Slack));
        self::assertSame($config, $config->setChannelConfig([]));
        self::assertSame($config, $config->setMinSeverity(DriftSeverity::Info));
        self::assertSame($config, $config->setIsActive(true));
    }

    #[Test]
    public function channelConfigCanStoreComplexData(): void
    {
        $config = new AlertConfiguration();
        $complexConfig = [
            'webhook_url' => 'https://hooks.slack.com/services/xxx',
            'channel' => '#alerts',
            'username' => 'Sentinel Bot',
            'icon_emoji' => ':warning:',
            'nested' => [
                'key' => 'value',
            ],
        ];

        $config->setChannelConfig($complexConfig);

        self::assertSame($complexConfig, $config->getChannelConfig());
    }
}
