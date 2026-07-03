<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Entity\UserPreferences;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UserPreferences::class)]
final class UserPreferencesTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $user = $this->createUser();
        $preferences = new UserPreferences($user);

        self::assertInstanceOf(Uuid::class, $preferences->getId());
        self::assertSame($user, $preferences->getUser());
        self::assertSame(UserPreferences::DEFAULT_DATE_RANGE, $preferences->getDefaultDateRange());
        self::assertSame(UserPreferences::DEFAULT_REFRESH_INTERVAL, $preferences->getRefreshInterval());
        self::assertSame(UserPreferences::DEFAULT_THEME, $preferences->getTheme());
        self::assertSame(UserPreferences::DEFAULT_TIMEZONE, $preferences->getTimezone());
        self::assertInstanceOf(\DateTimeImmutable::class, $preferences->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $preferences->getUpdatedAt());
    }

    #[Test]
    public function setAndGetDefaultDateRange(): void
    {
        $preferences = new UserPreferences($this->createUser());

        $result = $preferences->setDefaultDateRange('7d');

        self::assertSame($preferences, $result);
        self::assertSame('7d', $preferences->getDefaultDateRange());
    }

    #[Test]
    public function setDefaultDateRangeThrowsForInvalidValue(): void
    {
        $preferences = new UserPreferences($this->createUser());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date range: invalid');

        $preferences->setDefaultDateRange('invalid');
    }

    #[Test]
    public function setAndGetRefreshInterval(): void
    {
        $preferences = new UserPreferences($this->createUser());

        $result = $preferences->setRefreshInterval(60000);

        self::assertSame($preferences, $result);
        self::assertSame(60000, $preferences->getRefreshInterval());
    }

    #[Test]
    public function setRefreshIntervalThrowsForInvalidValue(): void
    {
        $preferences = new UserPreferences($this->createUser());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid refresh interval: 12345');

        $preferences->setRefreshInterval(12345);
    }

    #[Test]
    public function setAndGetNotificationEvents(): void
    {
        $preferences = new UserPreferences($this->createUser());
        $events = ['drift_detected', 'health_change'];

        $result = $preferences->setNotificationEvents($events);

        self::assertSame($preferences, $result);
        self::assertSame($events, $preferences->getNotificationEvents());
    }

    #[Test]
    public function setNotificationEventsThrowsForInvalidEvent(): void
    {
        $preferences = new UserPreferences($this->createUser());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid notification event: invalid_event');

        $preferences->setNotificationEvents(['drift_detected', 'invalid_event']);
    }

    #[Test]
    public function hasNotificationEventReturnsTrueForEnabledEvent(): void
    {
        $preferences = new UserPreferences($this->createUser());
        $preferences->setNotificationEvents(['drift_detected', 'health_change']);

        self::assertTrue($preferences->hasNotificationEvent('drift_detected'));
        self::assertTrue($preferences->hasNotificationEvent('health_change'));
    }

    #[Test]
    public function hasNotificationEventReturnsFalseForDisabledEvent(): void
    {
        $preferences = new UserPreferences($this->createUser());
        $preferences->setNotificationEvents(['drift_detected']);

        self::assertFalse($preferences->hasNotificationEvent('health_change'));
        self::assertFalse($preferences->hasNotificationEvent('threshold_exceeded'));
    }

    #[Test]
    public function setAndGetTheme(): void
    {
        $preferences = new UserPreferences($this->createUser());

        $result = $preferences->setTheme('dark');

        self::assertSame($preferences, $result);
        self::assertSame('dark', $preferences->getTheme());
    }

    #[Test]
    public function setThemeThrowsForInvalidValue(): void
    {
        $preferences = new UserPreferences($this->createUser());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid theme: invalid');

        $preferences->setTheme('invalid');
    }

    #[Test]
    public function setAndGetTimezone(): void
    {
        $preferences = new UserPreferences($this->createUser());

        $result = $preferences->setTimezone('America/New_York');

        self::assertSame($preferences, $result);
        self::assertSame('America/New_York', $preferences->getTimezone());
    }

    #[Test]
    public function setTimezoneThrowsForInvalidValue(): void
    {
        $preferences = new UserPreferences($this->createUser());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timezone: Invalid/Timezone');

        $preferences->setTimezone('Invalid/Timezone');
    }

    #[Test]
    public function updateTimestampUpdatesUpdatedAt(): void
    {
        $preferences = new UserPreferences($this->createUser());
        $originalUpdatedAt = $preferences->getUpdatedAt();

        usleep(1000);
        $preferences->updateTimestamp();

        self::assertGreaterThan($originalUpdatedAt, $preferences->getUpdatedAt());
    }

    #[Test]
    public function dateRangeOptionsContainsExpectedValues(): void
    {
        self::assertArrayHasKey('1h', UserPreferences::DATE_RANGE_OPTIONS);
        self::assertArrayHasKey('6h', UserPreferences::DATE_RANGE_OPTIONS);
        self::assertArrayHasKey('24h', UserPreferences::DATE_RANGE_OPTIONS);
        self::assertArrayHasKey('7d', UserPreferences::DATE_RANGE_OPTIONS);
        self::assertArrayHasKey('30d', UserPreferences::DATE_RANGE_OPTIONS);
    }

    #[Test]
    public function refreshIntervalOptionsContainsExpectedValues(): void
    {
        self::assertArrayHasKey(0, UserPreferences::REFRESH_INTERVAL_OPTIONS);
        self::assertArrayHasKey(10000, UserPreferences::REFRESH_INTERVAL_OPTIONS);
        self::assertArrayHasKey(30000, UserPreferences::REFRESH_INTERVAL_OPTIONS);
        self::assertArrayHasKey(60000, UserPreferences::REFRESH_INTERVAL_OPTIONS);
        self::assertArrayHasKey(300000, UserPreferences::REFRESH_INTERVAL_OPTIONS);
    }

    #[Test]
    public function themeOptionsContainsExpectedValues(): void
    {
        self::assertArrayHasKey('light', UserPreferences::THEME_OPTIONS);
        self::assertArrayHasKey('dark', UserPreferences::THEME_OPTIONS);
        self::assertArrayHasKey('system', UserPreferences::THEME_OPTIONS);
    }

    #[Test]
    public function notificationEventOptionsContainsExpectedValues(): void
    {
        self::assertArrayHasKey('drift_detected', UserPreferences::NOTIFICATION_EVENT_OPTIONS);
        self::assertArrayHasKey('health_change', UserPreferences::NOTIFICATION_EVENT_OPTIONS);
        self::assertArrayHasKey('threshold_exceeded', UserPreferences::NOTIFICATION_EVENT_OPTIONS);
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setPassword('hashed');

        return $user;
    }
}
