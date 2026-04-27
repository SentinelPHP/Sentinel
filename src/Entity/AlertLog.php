<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AlertChannelType;
use App\Repository\AlertLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AlertLogRepository::class)]
#[ORM\Table(name: 'alert_logs')]
#[ORM\Index(columns: ['alert_configuration_id'], name: 'IDX_ALERT_LOGS_CONFIG_ID')]
#[ORM\Index(columns: ['drift_id'], name: 'IDX_ALERT_LOGS_DRIFT_ID')]
#[ORM\Index(columns: ['channel_type'], name: 'IDX_ALERT_LOGS_CHANNEL_TYPE')]
#[ORM\Index(columns: ['status'], name: 'IDX_ALERT_LOGS_STATUS')]
#[ORM\Index(columns: ['created_at'], name: 'IDX_ALERT_LOGS_CREATED_AT')]
class AlertLog
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AlertConfiguration::class)]
    #[ORM\JoinColumn(name: 'alert_configuration_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AlertConfiguration $alertConfiguration = null;

    #[ORM\ManyToOne(targetEntity: SchemaDrift::class)]
    #[ORM\JoinColumn(name: 'drift_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SchemaDrift $drift = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: AlertChannelType::class)]
    private AlertChannelType $channelType;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public static function success(
        AlertChannelType $channelType,
        ?AlertConfiguration $config = null,
        ?SchemaDrift $drift = null,
        ?array $payload = null,
    ): self {
        $log = new self();
        $log->channelType = $channelType;
        $log->alertConfiguration = $config;
        $log->drift = $drift;
        $log->status = self::STATUS_SUCCESS;
        $log->payload = $payload;

        return $log;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public static function failure(
        AlertChannelType $channelType,
        string $errorMessage,
        ?AlertConfiguration $config = null,
        ?SchemaDrift $drift = null,
        ?array $payload = null,
    ): self {
        $log = new self();
        $log->channelType = $channelType;
        $log->alertConfiguration = $config;
        $log->drift = $drift;
        $log->status = self::STATUS_FAILURE;
        $log->errorMessage = $errorMessage;
        $log->payload = $payload;

        return $log;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAlertConfiguration(): ?AlertConfiguration
    {
        return $this->alertConfiguration;
    }

    public function getDrift(): ?SchemaDrift
    {
        return $this->drift;
    }

    public function getChannelType(): AlertChannelType
    {
        return $this->channelType;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
