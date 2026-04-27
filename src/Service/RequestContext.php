<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped context holder for tracking request metadata across the request lifecycle.
 * Implements ResetInterface to be reset between Swoole requests.
 */
final class RequestContext implements ResetInterface
{
    private ?string $requestId = null;
    private ?string $tokenId = null;
    private ?string $targetUrl = null;
    private ?string $targetHost = null;

    public function initialize(): void
    {
        $this->requestId = Uuid::v4()->toRfc4122();
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setTokenId(?string $tokenId): void
    {
        $this->tokenId = $tokenId;
    }

    public function getTokenId(): ?string
    {
        return $this->tokenId;
    }

    public function setTargetUrl(?string $targetUrl): void
    {
        $this->targetUrl = $targetUrl;

        if ($targetUrl !== null) {
            $host = parse_url($targetUrl, PHP_URL_HOST);
            $this->targetHost = is_string($host) ? $host : null;
        } else {
            $this->targetHost = null;
        }
    }

    public function getTargetUrl(): ?string
    {
        return $this->targetUrl;
    }

    public function getTargetHost(): ?string
    {
        return $this->targetHost;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'token_id' => $this->tokenId,
            'target_url' => $this->targetUrl,
            'target_host' => $this->targetHost,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toLogContext(): array
    {
        return array_filter($this->toArray(), fn ($value) => $value !== null);
    }

    public function reset(): void
    {
        $this->requestId = null;
        $this->tokenId = null;
        $this->targetUrl = null;
        $this->targetHost = null;
    }
}
