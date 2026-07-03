<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RequestLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use App\Entity\SchemaDrift;
use App\Entity\DriftPayload;

#[ORM\Entity(repositoryClass: RequestLogRepository::class)]
#[ORM\Table(name: 'request_logs')]
#[ORM\Index(columns: ['token_id'], name: 'IDX_REQUEST_LOGS_TOKEN_ID')]
#[ORM\Index(columns: ['created_at'], name: 'IDX_REQUEST_LOGS_CREATED_AT')]
#[ORM\Index(columns: ['drift_id'], name: 'IDX_REQUEST_LOGS_DRIFT_ID')]
class RequestLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(name: 'token_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ApiToken $token = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $targetHost;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $requestMethod;

    #[ORM\Column(type: Types::STRING, length: 2048)]
    private string $requestPath;

    #[ORM\Column(type: Types::INTEGER)]
    private int $responseStatusCode;

    #[ORM\Column(type: Types::INTEGER)]
    private int $latencyMs;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $requestHeaders = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $requestBody = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseHeaders = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $schemaValidated = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $driftDetected = null;

    #[ORM\ManyToOne(targetEntity: SchemaDrift::class)]
    #[ORM\JoinColumn(name: 'drift_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SchemaDrift $drift = null;

    #[ORM\OneToOne(targetEntity: DriftPayload::class, mappedBy: 'requestLog')]
    private ?DriftPayload $driftPayload = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isEncrypted = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isCompressed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getTargetHost(): string
    {
        return $this->targetHost;
    }

    public function setTargetHost(string $targetHost): self
    {
        $this->targetHost = $targetHost;
        return $this;
    }

    public function getRequestMethod(): string
    {
        return $this->requestMethod;
    }

    public function setRequestMethod(string $requestMethod): self
    {
        $this->requestMethod = $requestMethod;
        return $this;
    }

    public function getRequestPath(): string
    {
        return $this->requestPath;
    }

    public function setRequestPath(string $requestPath): self
    {
        $this->requestPath = $requestPath;
        return $this;
    }

    public function getResponseStatusCode(): int
    {
        return $this->responseStatusCode;
    }

    public function setResponseStatusCode(int $responseStatusCode): self
    {
        $this->responseStatusCode = $responseStatusCode;
        return $this;
    }

    public function getLatencyMs(): int
    {
        return $this->latencyMs;
    }

    public function setLatencyMs(int $latencyMs): self
    {
        $this->latencyMs = $latencyMs;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRequestHeaders(): ?string
    {
        return $this->requestHeaders;
    }

    public function setRequestHeaders(?string $requestHeaders): self
    {
        $this->requestHeaders = $requestHeaders;
        return $this;
    }

    public function getRequestBody(): ?string
    {
        return $this->requestBody;
    }

    public function setRequestBody(?string $requestBody): self
    {
        $this->requestBody = $requestBody;
        return $this;
    }

    public function getResponseHeaders(): ?string
    {
        return $this->responseHeaders;
    }

    public function setResponseHeaders(?string $responseHeaders): self
    {
        $this->responseHeaders = $responseHeaders;
        return $this;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function setResponseBody(?string $responseBody): self
    {
        $this->responseBody = $responseBody;
        return $this;
    }

    public function isSchemaValidated(): ?bool
    {
        return $this->schemaValidated;
    }

    public function setSchemaValidated(?bool $schemaValidated): self
    {
        $this->schemaValidated = $schemaValidated;
        return $this;
    }

    public function isDriftDetected(): ?bool
    {
        return $this->driftDetected;
    }

    public function setDriftDetected(?bool $driftDetected): self
    {
        $this->driftDetected = $driftDetected;
        return $this;
    }

    public function getDrift(): ?SchemaDrift
    {
        return $this->drift;
    }

    public function setDrift(?SchemaDrift $drift): self
    {
        $this->drift = $drift;
        return $this;
    }

    public function getDriftPayload(): ?DriftPayload
    {
        return $this->driftPayload;
    }

    public function setDriftPayload(?DriftPayload $driftPayload): self
    {
        $this->driftPayload = $driftPayload;
        return $this;
    }

    public function isEncrypted(): bool
    {
        return $this->isEncrypted;
    }

    public function setIsEncrypted(bool $isEncrypted): self
    {
        $this->isEncrypted = $isEncrypted;
        return $this;
    }

    public function isCompressed(): bool
    {
        return $this->isCompressed;
    }

    public function setIsCompressed(bool $isCompressed): self
    {
        $this->isCompressed = $isCompressed;
        return $this;
    }

    public function getDecompressedRequestHeaders(): ?string
    {
        return $this->decompressIfNeeded($this->requestHeaders);
    }

    public function getDecompressedRequestBody(): ?string
    {
        return $this->decompressIfNeeded($this->requestBody);
    }

    public function getDecompressedResponseHeaders(): ?string
    {
        return $this->decompressIfNeeded($this->responseHeaders);
    }

    public function getDecompressedResponseBody(): ?string
    {
        return $this->decompressIfNeeded($this->responseBody);
    }

    private function decompressIfNeeded(?string $data): ?string
    {
        if ($data === null || $data === '' || !$this->isCompressed) {
            return $data;
        }

        if (!str_starts_with($data, 'gzip:')) {
            return $data;
        }

        $encoded = substr($data, 5);
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return $data;
        }

        $decompressed = @gzdecode($decoded);

        return $decompressed === false ? $data : $decompressed;
    }
}
