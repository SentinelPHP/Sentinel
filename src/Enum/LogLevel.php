<?php

declare(strict_types=1);

namespace App\Enum;

enum LogLevel: string
{
    case None = 'none';
    case MetadataOnly = 'metadata_only';
    case DriftOnly = 'drift_only';
    case Headers = 'headers';
    case FullAudit = 'full_audit';

    /**
     * Returns the fields that should be logged for this level.
     *
     * @return array{requestHeaders: bool, requestBody: bool, responseHeaders: bool, responseBody: bool}
     */
    public function getLoggedFields(): array
    {
        return match ($this) {
            self::None, self::MetadataOnly, self::DriftOnly => [
                'requestHeaders' => false,
                'requestBody' => false,
                'responseHeaders' => false,
                'responseBody' => false,
            ],
            self::Headers => [
                'requestHeaders' => true,
                'requestBody' => false,
                'responseHeaders' => true,
                'responseBody' => false,
            ],
            self::FullAudit => [
                'requestHeaders' => true,
                'requestBody' => true,
                'responseHeaders' => true,
                'responseBody' => true,
            ],
        };
    }

    public function shouldSkipLogging(): bool
    {
        return $this === self::None;
    }

    public function shouldLogBodiesOnDrift(): bool
    {
        return $this === self::DriftOnly;
    }

    public function shouldLogHeadersOnDrift(): bool
    {
        return $this === self::DriftOnly;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
