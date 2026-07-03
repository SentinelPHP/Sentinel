<?php

declare(strict_types=1);

namespace App\Entity;

use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use App\Repository\SchemaDriftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SchemaDriftRepository::class)]
#[ORM\Table(name: 'schema_drifts')]
#[ORM\Index(columns: ['schema_id'], name: 'IDX_SCHEMA_DRIFTS_SCHEMA_ID')]
#[ORM\Index(columns: ['token_id'], name: 'IDX_SCHEMA_DRIFTS_TOKEN_ID')]
#[ORM\Index(columns: ['created_at'], name: 'IDX_SCHEMA_DRIFTS_CREATED_AT')]
#[ORM\Index(columns: ['severity'], name: 'IDX_SCHEMA_DRIFTS_SEVERITY')]
class SchemaDrift
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ApiSchema::class)]
    #[ORM\JoinColumn(name: 'schema_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ApiSchema $schema;

    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(name: 'token_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ApiToken $token;

    #[ORM\ManyToOne(targetEntity: RequestLog::class)]
    #[ORM\JoinColumn(name: 'request_log_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?RequestLog $requestLog = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DriftType::class)]
    private DriftType $driftType;

    #[ORM\Column(type: Types::STRING, length: 2048)]
    private string $path;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $expectedValue = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $actualValue = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DriftSeverity::class)]
    private DriftSeverity $severity;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'accepted_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptedBy = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSchema(): ApiSchema
    {
        return $this->schema;
    }

    public function setSchema(ApiSchema $schema): self
    {
        $this->schema = $schema;
        return $this;
    }

    public function getToken(): ApiToken
    {
        return $this->token;
    }

    public function setToken(ApiToken $token): self
    {
        $this->token = $token;
        return $this;
    }

    public function getRequestLog(): ?RequestLog
    {
        return $this->requestLog;
    }

    public function setRequestLog(?RequestLog $requestLog): self
    {
        $this->requestLog = $requestLog;
        return $this;
    }

    public function getDriftType(): DriftType
    {
        return $this->driftType;
    }

    public function setDriftType(DriftType $driftType): self
    {
        $this->driftType = $driftType;
        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExpectedValue(): ?array
    {
        return $this->expectedValue;
    }

    /**
     * @param array<string, mixed>|null $expectedValue
     */
    public function setExpectedValue(?array $expectedValue): self
    {
        $this->expectedValue = $expectedValue;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActualValue(): ?array
    {
        return $this->actualValue;
    }

    /**
     * @param array<string, mixed>|null $actualValue
     */
    public function setActualValue(?array $actualValue): self
    {
        $this->actualValue = $actualValue;
        return $this;
    }

    public function getSeverity(): DriftSeverity
    {
        return $this->severity;
    }

    public function setSeverity(DriftSeverity $severity): self
    {
        $this->severity = $severity;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): self
    {
        $this->acceptedAt = $acceptedAt;
        return $this;
    }

    public function getAcceptedBy(): ?User
    {
        return $this->acceptedBy;
    }

    public function setAcceptedBy(?User $acceptedBy): self
    {
        $this->acceptedBy = $acceptedBy;
        return $this;
    }

    public function isAccepted(): bool
    {
        return $this->acceptedAt !== null;
    }
}
