<?php

declare(strict_types=1);

namespace App\Enum;

enum AlertChannelType: string
{
    case Slack = 'slack';
    case Webhook = 'webhook';
    case Email = 'email';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
