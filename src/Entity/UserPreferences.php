<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserPreferencesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserPreferencesRepository::class)]
#[ORM\Table(name: 'user_preferences')]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_PREFERENCES_USER', fields: ['user'])]
#[ORM\HasLifecycleCallbacks]
class UserPreferences
{
    public const DEFAULT_DATE_RANGE = '24h';
    public const DEFAULT_REFRESH_INTERVAL = 30000;
    public const DEFAULT_THEME = 'light';
    public const DEFAULT_TIMEZONE = 'UTC';

    public const DATE_RANGE_OPTIONS = [
        '1h' => 'Last hour',
        '6h' => 'Last 6 hours',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
    ];

    public const REFRESH_INTERVAL_OPTIONS = [
        0 => 'Off',
        10000 => '10 seconds',
        30000 => '30 seconds',
        60000 => '1 minute',
        300000 => '5 minutes',
    ];

    public const THEME_OPTIONS = [
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
    ];

    public const NOTIFICATION_EVENT_OPTIONS = [
        'drift_detected' => 'Drift detected',
        'health_change' => 'Service health change',
        'threshold_exceeded' => 'Threshold exceeded',
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $defaultDateRange = self::DEFAULT_DATE_RANGE;

    #[ORM\Column(type: Types::INTEGER)]
    private int $refreshInterval = self::DEFAULT_REFRESH_INTERVAL;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $notificationEvents = ['drift_detected', 'health_change', 'threshold_exceeded'];

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $theme = self::DEFAULT_THEME;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $timezone = self::DEFAULT_TIMEZONE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDefaultDateRange(): string
    {
        return $this->defaultDateRange;
    }

    public function setDefaultDateRange(string $defaultDateRange): self
    {
        if (!array_key_exists($defaultDateRange, self::DATE_RANGE_OPTIONS)) {
            throw new \InvalidArgumentException(sprintf('Invalid date range: %s', $defaultDateRange));
        }
        $this->defaultDateRange = $defaultDateRange;
        return $this;
    }

    public function getRefreshInterval(): int
    {
        return $this->refreshInterval;
    }

    public function setRefreshInterval(int $refreshInterval): self
    {
        if (!array_key_exists($refreshInterval, self::REFRESH_INTERVAL_OPTIONS)) {
            throw new \InvalidArgumentException(sprintf('Invalid refresh interval: %d', $refreshInterval));
        }
        $this->refreshInterval = $refreshInterval;
        return $this;
    }

    /**
     * @return list<string>
     */
    public function getNotificationEvents(): array
    {
        return $this->notificationEvents;
    }

    /**
     * @param list<string> $notificationEvents
     */
    public function setNotificationEvents(array $notificationEvents): self
    {
        $validEvents = array_keys(self::NOTIFICATION_EVENT_OPTIONS);
        foreach ($notificationEvents as $event) {
            if (!in_array($event, $validEvents, true)) {
                throw new \InvalidArgumentException(sprintf('Invalid notification event: %s', $event));
            }
        }
        $this->notificationEvents = $notificationEvents;
        return $this;
    }

    public function hasNotificationEvent(string $event): bool
    {
        return in_array($event, $this->notificationEvents, true);
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): self
    {
        if (!array_key_exists($theme, self::THEME_OPTIONS)) {
            throw new \InvalidArgumentException(sprintf('Invalid theme: %s', $theme));
        }
        $this->theme = $theme;
        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): self
    {
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(sprintf('Invalid timezone: %s', $timezone));
        }
        $this->timezone = $timezone;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
