<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\HttpClientException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpClientException::class)]
final class HttpClientExceptionTest extends TestCase
{
    #[Test]
    public function connectionFailedCreatesExceptionWithMessage(): void
    {
        $exception = HttpClientException::connectionFailed('example.com', 443, 'Connection refused');

        self::assertStringContainsString('example.com', $exception->getMessage());
        self::assertStringContainsString('443', $exception->getMessage());
        self::assertStringContainsString('Connection refused', $exception->getMessage());
    }

    #[Test]
    public function requestFailedCreatesExceptionWithMessage(): void
    {
        $exception = HttpClientException::requestFailed('https://example.com/api', 'Timeout');

        self::assertStringContainsString('https://example.com/api', $exception->getMessage());
        self::assertStringContainsString('Timeout', $exception->getMessage());
    }

    #[Test]
    public function invalidUrlCreatesExceptionWithMessage(): void
    {
        $exception = HttpClientException::invalidUrl('not-a-valid-url');

        self::assertStringContainsString('not-a-valid-url', $exception->getMessage());
    }
}
