<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class AlertResult
{
    private function __construct(
        public bool $success,
        public string $channelName,
        public ?string $errorMessage,
        public \DateTimeImmutable $sentAt,
    ) {
    }

    public static function success(string $channelName): self
    {
        return new self(
            success: true,
            channelName: $channelName,
            errorMessage: null,
            sentAt: new \DateTimeImmutable(),
        );
    }

    public static function failure(string $channelName, string $errorMessage): self
    {
        return new self(
            success: false,
            channelName: $channelName,
            errorMessage: $errorMessage,
            sentAt: new \DateTimeImmutable(),
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return !$this->success;
    }
}
