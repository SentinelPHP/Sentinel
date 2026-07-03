<?php

declare(strict_types=1);

namespace App\Enum;

enum TokenMode: string
{
    case Learning = 'learning';
    case Validating = 'validating';
    case Passive = 'passive';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
