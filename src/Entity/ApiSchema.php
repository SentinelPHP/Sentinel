<?php

declare(strict_types=1);

namespace App\Entity;

use SentinelPHP\Dto\Enum\SchemaType;
use App\Repository\ApiSchemaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ApiSchemaRepository::class)]
#[ORM\Table(name: 'api_schemas')]
#[ORM\Index(columns: ['token_id'], name: 'IDX_API_SCHEMAS_TOKEN_ID')]
#[ORM\Index(columns: ['token_id', 'target_host', 'endpoint_path', 'http_method', 'schema_type'], name: 'IDX_API_SCHEMAS_LOOKUP')]
#[ORM\Index(columns: ['token_id', 'is_master'], name: 'IDX_API_SCHEMAS_MASTER')]
#[ORM\HasLifecycleCallbacks]
class ApiSchema
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(name: 'token_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ApiToken $token;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $targetHost;

    #[ORM\Column(type: Types::STRING, length: 2048)]
    private string $endpointPath;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $httpMethod;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: SchemaType::class)]
    private SchemaType $schemaType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $jsonSchema = [];

    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isMaster = false;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sampleCount = 1;

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

    public function getToken(): ApiToken
    {
        return $this->token;
    }

    public function setToken(ApiToken $token): self
    {
        $this->token = $token;
        return $this;
    }

    public function getTargetHost(): string
    {
        return $this->targetHost;
    }

    public function setTargetHost(string $targetHost): self
    {
        $this->targetHost = $targetHost;
        return $this;
    }

    public function getEndpointPath(): string
    {
        return $this->endpointPath;
    }

    public function setEndpointPath(string $endpointPath): self
    {
        $this->endpointPath = $endpointPath;
        return $this;
    }

    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    public function setHttpMethod(string $httpMethod): self
    {
        $this->httpMethod = strtoupper($httpMethod);
        return $this;
    }

    public function getSchemaType(): SchemaType
    {
        return $this->schemaType;
    }

    public function setSchemaType(SchemaType $schemaType): self
    {
        $this->schemaType = $schemaType;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getJsonSchema(): array
    {
        return $this->jsonSchema;
    }

    /**
     * @param array<string, mixed> $jsonSchema
     */
    public function setJsonSchema(array $jsonSchema): self
    {
        $this->jsonSchema = $jsonSchema;
        return $this;
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

    public function incrementVersion(): self
    {
        $this->version++;
        return $this;
    }

    public function isMaster(): bool
    {
        return $this->isMaster;
    }

    public function setIsMaster(bool $isMaster): self
    {
        $this->isMaster = $isMaster;
        return $this;
    }

    public function getSampleCount(): int
    {
        return $this->sampleCount;
    }

    public function setSampleCount(int $sampleCount): self
    {
        $this->sampleCount = $sampleCount;
        return $this;
    }

    public function incrementSampleCount(): self
    {
        $this->sampleCount++;
        return $this;
    }

    public function isStable(int $minSamples): bool
    {
        return $this->sampleCount >= $minSamples;
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
