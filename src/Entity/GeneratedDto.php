<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DtoGenerationStatus;
use App\Repository\GeneratedDtoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GeneratedDtoRepository::class)]
#[ORM\Table(name: 'generated_dtos')]
#[ORM\Index(columns: ['schema_id'], name: 'IDX_GENERATED_DTOS_SCHEMA')]
#[ORM\Index(columns: ['class_name'], name: 'IDX_GENERATED_DTOS_CLASS_NAME')]
#[ORM\Index(columns: ['schema_id', 'is_current'], name: 'IDX_GENERATED_DTOS_CURRENT')]
#[ORM\UniqueConstraint(name: 'UNIQ_GENERATED_DTOS_SCHEMA_VERSION', columns: ['schema_id', 'version'])]
class GeneratedDto
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ApiSchema::class)]
    #[ORM\JoinColumn(name: 'schema_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ApiSchema $schema;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $className;

    #[ORM\Column(type: Types::STRING, length: 512)]
    private string $namespace;

    #[ORM\Column(type: Types::TEXT)]
    private string $phpCode;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $checksum;

    #[ORM\Column(type: Types::INTEGER)]
    private int $version;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isCurrent = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DtoGenerationStatus::class)]
    private DtoGenerationStatus $status = DtoGenerationStatus::Completed;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

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

    public function getClassName(): string
    {
        return $this->className;
    }

    public function setClassName(string $className): self
    {
        $this->className = $className;
        return $this;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function setNamespace(string $namespace): self
    {
        $this->namespace = $namespace;
        return $this;
    }

    public function getPhpCode(): string
    {
        return $this->phpCode;
    }

    public function setPhpCode(string $phpCode): self
    {
        $this->phpCode = $phpCode;
        $this->checksum = self::computeChecksum($phpCode);
        return $this;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function isCurrent(): bool
    {
        return $this->isCurrent;
    }

    public function setIsCurrent(bool $isCurrent): self
    {
        $this->isCurrent = $isCurrent;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFullyQualifiedClassName(): string
    {
        return $this->namespace . '\\' . $this->className;
    }

    public static function computeChecksum(string $phpCode): string
    {
        return hash('sha256', $phpCode);
    }

    public function getStatus(): DtoGenerationStatus
    {
        return $this->status;
    }

    public function setStatus(DtoGenerationStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }
}
