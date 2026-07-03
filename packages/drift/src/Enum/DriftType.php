<?php

declare(strict_types=1);

namespace SentinelPHP\Drift\Enum;

enum DriftType: string
{
    case FieldAdded = 'field_added';
    case FieldRemoved = 'field_removed';
    case TypeChanged = 'type_changed';
    case StructureChanged = 'structure_changed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
