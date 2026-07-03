<?php

declare(strict_types=1);

namespace App\Http;

final readonly class HttpResponse
{
    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {
    }

    public function getHeader(string $name): ?string
    {
        $lowerName = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $lowerName) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }
}
