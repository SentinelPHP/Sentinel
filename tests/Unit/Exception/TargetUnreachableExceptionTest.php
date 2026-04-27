<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Exception\TargetUnreachableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(TargetUnreachableException::class)]
final class TargetUnreachableExceptionTest extends TestCase
{
    #[Test]
    public function connectionFailedCreatesExceptionWithHostAndPort(): void
    {
        $exception = TargetUnreachableException::connectionFailed('api.example.com', 443, 'Connection refused');

        $this->assertSame('Failed to connect to api.example.com:443 - Connection refused', $exception->getMessage());
        $this->assertSame('api.example.com', $exception->targetHost);
        $this->assertSame('api.example.com:443', $exception->targetUrl);
        $this->assertSame(502, $exception->getHttpStatusCode());
    }

    #[Test]
    public function connectionFailedPreservesPreviousException(): void
    {
        $previous = new RuntimeException('Original error');
        $exception = TargetUnreachableException::connectionFailed('api.example.com', 443, 'Connection refused', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function timeoutCreatesExceptionWithUrlAndDuration(): void
    {
        $exception = TargetUnreachableException::timeout('https://api.example.com/endpoint', 30);

        $this->assertSame('Request to https://api.example.com/endpoint timed out after 30 seconds', $exception->getMessage());
        $this->assertSame('api.example.com', $exception->targetHost);
        $this->assertSame('https://api.example.com/endpoint', $exception->targetUrl);
        $this->assertSame(502, $exception->getHttpStatusCode());
    }

    #[Test]
    public function dnsResolutionFailedCreatesExceptionWithHost(): void
    {
        $exception = TargetUnreachableException::dnsResolutionFailed('nonexistent.example.com');

        $this->assertSame('DNS resolution failed for host: nonexistent.example.com', $exception->getMessage());
        $this->assertSame('nonexistent.example.com', $exception->targetHost);
        $this->assertNull($exception->targetUrl);
        $this->assertSame(502, $exception->getHttpStatusCode());
    }

    #[Test]
    public function requestFailedCreatesExceptionWithUrlAndReason(): void
    {
        $exception = TargetUnreachableException::requestFailed('https://api.example.com/data', 'SSL certificate error');

        $this->assertSame('Request to https://api.example.com/data failed - SSL certificate error', $exception->getMessage());
        $this->assertSame('api.example.com', $exception->targetHost);
        $this->assertSame('https://api.example.com/data', $exception->targetUrl);
        $this->assertSame(502, $exception->getHttpStatusCode());
    }
}
