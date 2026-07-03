<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class AlertTestResult
{
    private function __construct(
        private bool $success,
        private string $message,
    ) {
    }

    public static function success(string $message = 'Test alert sent successfully.'): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
