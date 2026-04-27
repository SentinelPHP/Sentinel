<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Entity\UserPreferences;
use App\Service\UserPreferencesServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class UserPreferencesExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserPreferencesServiceInterface $preferencesService,
        private readonly Security $security,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('user_timezone', [$this, 'formatInUserTimezone']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_preferences', [$this, 'getUserPreferences']),
            new TwigFunction('user_theme', [$this, 'getUserTheme']),
            new TwigFunction('user_timezone', [$this, 'getUserTimezone']),
            new TwigFunction('user_refresh_interval', [$this, 'getUserRefreshInterval']),
            new TwigFunction('user_date_range', [$this, 'getUserDateRange']),
            new TwigFunction('user_notification_events', [$this, 'getUserNotificationEvents']),
        ];
    }

    public function getUserPreferences(): ?UserPreferences
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return $this->preferencesService->getPreferences($user);
    }

    public function getUserTheme(): string
    {
        $preferences = $this->getUserPreferences();
        return $preferences?->getTheme() ?? UserPreferences::DEFAULT_THEME;
    }

    public function getUserTimezone(): string
    {
        $preferences = $this->getUserPreferences();
        return $preferences?->getTimezone() ?? UserPreferences::DEFAULT_TIMEZONE;
    }

    public function getUserRefreshInterval(): int
    {
        $preferences = $this->getUserPreferences();
        return $preferences?->getRefreshInterval() ?? UserPreferences::DEFAULT_REFRESH_INTERVAL;
    }

    public function getUserDateRange(): string
    {
        $preferences = $this->getUserPreferences();
        return $preferences?->getDefaultDateRange() ?? UserPreferences::DEFAULT_DATE_RANGE;
    }

    /**
     * @return list<string>
     */
    public function getUserNotificationEvents(): array
    {
        $preferences = $this->getUserPreferences();
        return $preferences?->getNotificationEvents() ?? ['drift_detected', 'health_change', 'threshold_exceeded'];
    }

    public function formatInUserTimezone(\DateTimeInterface $datetime, string $format = 'M j, Y H:i:s'): string
    {
        $timezone = new \DateTimeZone($this->getUserTimezone());
        
        if ($datetime instanceof \DateTimeImmutable) {
            $datetime = $datetime->setTimezone($timezone);
        } elseif ($datetime instanceof \DateTime) {
            $datetime = (clone $datetime)->setTimezone($timezone);
        }

        return $datetime->format($format);
    }
}
