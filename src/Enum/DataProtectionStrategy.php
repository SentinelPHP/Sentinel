<?php

declare(strict_types=1);

namespace App\Enum;

enum DataProtectionStrategy: string
{
    case None = 'none';
    case Redact = 'redact';
    case Encrypt = 'encrypt';
    case RedactEncrypt = 'redact_encrypt';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function shouldRedact(): bool
    {
        return $this === self::Redact || $this === self::RedactEncrypt;
    }

    public function shouldEncrypt(): bool
    {
        return $this === self::Encrypt || $this === self::RedactEncrypt;
    }
}
