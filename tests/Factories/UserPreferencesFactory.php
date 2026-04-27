<?php

namespace App\Tests\Factories;

use App\Entity\User;
use App\Entity\UserPreferences;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<UserPreferences>
 */
final class UserPreferencesFactory extends PersistentObjectFactory
{
    public function __construct()
    {
    }

    #[\Override]
    public static function class(): string
    {
        return UserPreferences::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     */
    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'user' => UserFactory::new(),
            'defaultDateRange' => UserPreferences::DEFAULT_DATE_RANGE,
            'refreshInterval' => UserPreferences::DEFAULT_REFRESH_INTERVAL,
            'notificationEvents' => ['drift_detected', 'health_change', 'threshold_exceeded'],
            'theme' => UserPreferences::DEFAULT_THEME,
            'timezone' => UserPreferences::DEFAULT_TIMEZONE,
        ];
    }

    public function withUser(User $user): static
    {
        return $this->with(['user' => $user]);
    }

    public function darkTheme(): static
    {
        return $this->with(['theme' => 'dark']);
    }

    public function lightTheme(): static
    {
        return $this->with(['theme' => 'light']);
    }

    public function systemTheme(): static
    {
        return $this->with(['theme' => 'system']);
    }

    public function withTimezone(string $timezone): static
    {
        return $this->with(['timezone' => $timezone]);
    }

    public function withDateRange(string $dateRange): static
    {
        return $this->with(['defaultDateRange' => $dateRange]);
    }

    public function withRefreshInterval(int $interval): static
    {
        return $this->with(['refreshInterval' => $interval]);
    }

    public function noRefresh(): static
    {
        return $this->with(['refreshInterval' => 0]);
    }

    /**
     * @param list<string> $events
     */
    public function withNotificationEvents(array $events): static
    {
        return $this->with(['notificationEvents' => $events]);
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]
    protected function initialize(): static
    {
        return $this;
    }
}
