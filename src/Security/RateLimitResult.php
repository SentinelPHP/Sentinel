<?php

declare(strict_types=1);

namespace App\Security;

final readonly class RateLimitResult
{
    private function __construct(
        public bool $isAllowed,
        public int $limit,
        public int $remaining,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    public static function allowed(int $limit, int $remaining): self
    {
        return new self(true, $limit, $remaining);
    }

    public static function denied(int $limit, int $remaining, int $retryAfterSeconds): self
    {
        return new self(false, $limit, $remaining, $retryAfterSeconds);
    }
}
