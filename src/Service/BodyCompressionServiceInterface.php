<?php

declare(strict_types=1);

namespace App\Service;

interface BodyCompressionServiceInterface
{
    public function compress(string $data): string;

    public function decompress(string $data): string;

    public function isCompressed(string $data): bool;
}
