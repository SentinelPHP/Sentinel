<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AlertChannelType;
use SentinelPHP\Drift\Enum\DriftSeverity;
use App\Repository\AlertConfigurationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AlertConfigurationRepository::class)]
#[ORM\Table(name: 'alert_configurations')]
#[ORM\Index(columns: ['token_id'], name: 'IDX_ALERT_CONFIGS_TOKEN_ID')]
#[ORM\Index(columns: ['channel_type'], name: 'IDX_ALERT_CONFIGS_CHANNEL_TYPE')]
#[ORM\Index(columns: ['is_active'], name: 'IDX_ALERT_CONFIGS_IS_ACTIVE')]
#[ORM\HasLifecycleCallbacks]
class AlertConfiguration
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(name: 'token_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?ApiToken $token = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: AlertChannelType::class)]
    private AlertChannelType $channelType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $channelConfig = [];

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DriftSeverity::class)]
    private DriftSeverity $minSeverity;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $mutedUntil = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $muteReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getToken(): ?ApiToken
    {
        return $this->token;
    }

    public function setToken(?ApiToken $token): self
    {
        $this->token = $token;
        return $this;
    }

    public function isGlobal(): bool
    {
        return $this->token === null;
    }

    public function getChannelType(): AlertChannelType
    {
        return $this->channelType;
    }

    public function setChannelType(AlertChannelType $channelType): self
    {
        $this->channelType = $channelType;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getChannelConfig(): array
    {
        return $this->channelConfig;
    }

    /**
     * @param array<string, mixed> $channelConfig
     */
    public function setChannelConfig(array $channelConfig): self
    {
        $this->channelConfig = $channelConfig;
        return $this;
    }

    public function getMinSeverity(): DriftSeverity
    {
        return $this->minSeverity;
    }

    public function setMinSeverity(DriftSeverity $minSeverity): self
    {
        $this->minSeverity = $minSeverity;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getMutedUntil(): ?\DateTimeImmutable
    {
        return $this->mutedUntil;
    }

    public function getMuteReason(): ?string
    {
        return $this->muteReason;
    }

    public function isMuted(): bool
    {
        if ($this->mutedUntil === null) {
            return false;
        }

        return $this->mutedUntil > new \DateTimeImmutable();
    }

    public function mute(\DateTimeImmutable $until, ?string $reason = null): self
    {
        $this->mutedUntil = $until;
        $this->muteReason = $reason;
        return $this;
    }

    public function unmute(): self
    {
        $this->mutedUntil = null;
        $this->muteReason = null;
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

    /**
     * Check if this configuration should alert for the given severity.
     */
    public function shouldAlertFor(DriftSeverity $severity): bool
    {
        if (!$this->isActive || $this->isMuted()) {
            return false;
        }

        $severityOrder = [
            DriftSeverity::Info->value => 0,
            DriftSeverity::Warning->value => 1,
            DriftSeverity::Critical->value => 2,
        ];

        return $severityOrder[$severity->value] >= $severityOrder[$this->minSeverity->value];
    }
}
