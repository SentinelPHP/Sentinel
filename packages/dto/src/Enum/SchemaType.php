<?php

declare(strict_types=1);

namespace SentinelPHP\Dto\Enum;

enum SchemaType: string
{
    case Request = 'request';
    case Response = 'response';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
