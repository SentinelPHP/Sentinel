<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Export modes for DTO file generation.
 */
enum ExportMode: string
{
    case SingleFile = 'single';
    case Bundled = 'bundled';
    case Full = 'full';
}
