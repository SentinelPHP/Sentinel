<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Exception\InvalidTargetException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvalidTargetException::class)]
final class InvalidTargetExceptionTest extends TestCase
{
    #[Test]
    public function missingHeaderCreatesExceptionWithHeaderName(): void
    {
        $exception = InvalidTargetException::missingHeader('X-Sentinel-Target');

        $this->assertSame('Missing required header: X-Sentinel-Target', $exception->getMessage());
        $this->assertNull($exception->targetUrl);
        $this->assertSame('missing_header', $exception->validationError);
        $this->assertSame(400, $exception->getHttpStatusCode());
    }

    #[Test]
    public function malformedUrlCreatesExceptionWithUrl(): void
    {
        $exception = InvalidTargetException::malformedUrl('not-a-valid-url');

        $this->assertSame('Invalid target URL: not-a-valid-url', $exception->getMessage());
        $this->assertSame('not-a-valid-url', $exception->targetUrl);
        $this->assertSame('malformed_url', $exception->validationError);
        $this->assertSame(403, $exception->getHttpStatusCode());
    }

    #[Test]
    public function hostNotAllowedCreatesExceptionWithHostInfo(): void
    {
        $exception = InvalidTargetException::hostNotAllowed('https://forbidden.com/api', 'forbidden.com');

        $this->assertSame('Target host is not allowed for this token: forbidden.com', $exception->getMessage());
        $this->assertSame('https://forbidden.com/api', $exception->targetUrl);
        $this->assertSame('host_not_allowed', $exception->validationError);
        $this->assertSame(403, $exception->getHttpStatusCode());
    }

    #[Test]
    public function privateIpBlockedCreatesExceptionWithIpInfo(): void
    {
        $exception = InvalidTargetException::privateIpBlocked('https://internal.local/api', '192.168.1.1');

        $this->assertSame('Target resolves to private/reserved IP address: 192.168.1.1', $exception->getMessage());
        $this->assertSame('https://internal.local/api', $exception->targetUrl);
        $this->assertSame('private_ip_blocked', $exception->validationError);
        $this->assertSame(403, $exception->getHttpStatusCode());
    }

    #[Test]
    public function validationFailedCreatesExceptionWithReason(): void
    {
        $exception = InvalidTargetException::validationFailed('https://example.com', 'Scheme must be https');

        $this->assertSame('Invalid target URL: Scheme must be https', $exception->getMessage());
        $this->assertSame('https://example.com', $exception->targetUrl);
        $this->assertSame('validation_failed', $exception->validationError);
        $this->assertSame(403, $exception->getHttpStatusCode());
    }
}
