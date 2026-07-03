<?php

declare(strict_types=1);

namespace App\Service;

final class BodyCompressionService implements BodyCompressionServiceInterface
{
    private const COMPRESSION_PREFIX = 'gzip:';

    public function compress(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $compressed = gzencode($data, 9);

        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress data');
        }

        return self::COMPRESSION_PREFIX . base64_encode($compressed);
    }

    public function decompress(string $data): string
    {
        if ($data === '') {
            return '';
        }

        if (!$this->isCompressed($data)) {
            return $data;
        }

        $encoded = substr($data, strlen(self::COMPRESSION_PREFIX));
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            throw new \RuntimeException('Failed to decode base64 data');
        }

        $decompressed = gzdecode($decoded);

        if ($decompressed === false) {
            throw new \RuntimeException('Failed to decompress data');
        }

        return $decompressed;
    }

    public function isCompressed(string $data): bool
    {
        return str_starts_with($data, self::COMPRESSION_PREFIX);
    }
}
