<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DriftPayloadRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DriftPayloadRepository::class)]
#[ORM\Table(name: 'drift_payloads')]
#[ORM\Index(columns: ['request_log_id'], name: 'IDX_DRIFT_PAYLOADS_REQUEST_LOG_ID')]
class DriftPayload
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: RequestLog::class, inversedBy: 'driftPayload')]
    #[ORM\JoinColumn(name: 'request_log_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private RequestLog $requestLog;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $requestBody = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $requestHeaders = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseHeaders = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isCompressed = false;

    public function __construct(?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRequestLog(): RequestLog
    {
        return $this->requestLog;
    }

    public function setRequestLog(RequestLog $requestLog): self
    {
        $this->requestLog = $requestLog;
        return $this;
    }

    public function getRequestBody(): ?string
    {
        return $this->requestBody;
    }

    public function getDecompressedRequestBody(): ?string
    {
        return $this->decompressIfNeeded($this->requestBody);
    }

    public function setRequestBody(?string $requestBody): self
    {
        $this->requestBody = $requestBody;
        return $this;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function getDecompressedResponseBody(): ?string
    {
        return $this->decompressIfNeeded($this->responseBody);
    }

    public function setResponseBody(?string $responseBody): self
    {
        $this->responseBody = $responseBody;
        return $this;
    }

    public function getRequestHeaders(): ?string
    {
        return $this->requestHeaders;
    }

    public function getDecompressedRequestHeaders(): ?string
    {
        return $this->decompressIfNeeded($this->requestHeaders);
    }

    public function setRequestHeaders(?string $requestHeaders): self
    {
        $this->requestHeaders = $requestHeaders;
        return $this;
    }

    public function getResponseHeaders(): ?string
    {
        return $this->responseHeaders;
    }

    public function getDecompressedResponseHeaders(): ?string
    {
        return $this->decompressIfNeeded($this->responseHeaders);
    }

    public function setResponseHeaders(?string $responseHeaders): self
    {
        $this->responseHeaders = $responseHeaders;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
