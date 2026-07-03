<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validation;

use App\Validation\TargetUrlValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TargetUrlValidator::class)]
final class TargetUrlValidatorTest extends TestCase
{
    #[Test]
    public function validateAcceptsValidPublicUrl(): void
    {
        $validator = new TargetUrlValidator();

        $result = $validator->validate('https://api.example.com/users');

        self::assertTrue($result->isValid);
        self::assertNull($result->error);
    }

    #[Test]
    public function validateAcceptsHttpUrl(): void
    {
        $validator = new TargetUrlValidator();

        $result = $validator->validate('http://api.example.com/users');

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validateRejectsInvalidUrlFormat(): void
    {
        $validator = new TargetUrlValidator();

        $result = $validator->validate('not-a-valid-url');

        self::assertFalse($result->isValid);
        self::assertStringContainsString('scheme', strtolower($result->error ?? ''));
    }

    #[Test]
    public function validateRejectsNonHttpSchemes(): void
    {
        $validator = new TargetUrlValidator();

        $result = $validator->validate('ftp://files.example.com/file.txt');

        self::assertFalse($result->isValid);
        self::assertStringContainsString('HTTP', $result->error ?? '');
    }

    #[Test]
    public function validateRejectsUrlWithoutHost(): void
    {
        $validator = new TargetUrlValidator();

        // URL with empty host
        $result = $validator->validate('http:///path/only');

        self::assertFalse($result->isValid);
        // parse_url returns false for malformed URLs
        self::assertNotNull($result->error);
    }

    #[Test]
    #[DataProvider('blockedHostsProvider')]
    public function validateRejectsBlockedHosts(string $url): void
    {
        $validator = new TargetUrlValidator();

        $result = $validator->validate($url);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('blocked', strtolower($result->error ?? ''));
    }

    /**
     * @return iterable<string, array{url: string}>
     */
    public static function blockedHostsProvider(): iterable
    {
        yield 'localhost' => ['url' => 'http://localhost/api'];
        yield '127.0.0.1' => ['url' => 'http://127.0.0.1/api'];
        yield '::1' => ['url' => 'http://[::1]/api'];
        yield '0.0.0.0' => ['url' => 'http://0.0.0.0/api'];
    }

    #[Test]
    #[DataProvider('privateIpProvider')]
    public function validateRejectsPrivateIpAddresses(string $url): void
    {
        $validator = new TargetUrlValidator();

        $result = $validator->validate($url);

        self::assertFalse($result->isValid);
        self::assertStringContainsString('private', strtolower($result->error ?? ''));
    }

    /**
     * @return iterable<string, array{url: string}>
     */
    public static function privateIpProvider(): iterable
    {
        yield '10.x.x.x range' => ['url' => 'http://10.0.0.1/api'];
        yield '172.16.x.x range' => ['url' => 'http://172.16.0.1/api'];
        yield '192.168.x.x range' => ['url' => 'http://192.168.1.1/api'];
        yield '169.254.x.x link-local' => ['url' => 'http://169.254.1.1/api'];
        yield '127.x.x.x loopback' => ['url' => 'http://127.0.0.2/api'];
    }

    #[Test]
    public function validateWithAllowedHostsAcceptsMatchingHost(): void
    {
        $validator = new TargetUrlValidator(['api.example.com', 'api.other.com']);

        $result = $validator->validate('https://api.example.com/users');

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validateWithAllowedHostsRejectsNonMatchingHost(): void
    {
        $validator = new TargetUrlValidator(['api.example.com']);

        $result = $validator->validate('https://api.other.com/users');

        self::assertFalse($result->isValid);
        self::assertStringContainsString('allowed', strtolower($result->error ?? ''));
    }

    #[Test]
    public function validateWithWildcardAllowedHostAcceptsSubdomains(): void
    {
        $validator = new TargetUrlValidator(['*.example.com']);

        $result1 = $validator->validate('https://api.example.com/users');
        $result2 = $validator->validate('https://admin.example.com/users');

        self::assertTrue($result1->isValid);
        self::assertTrue($result2->isValid);
    }

    #[Test]
    public function validateWithWildcardDoesNotMatchExactDomain(): void
    {
        $validator = new TargetUrlValidator(['*.example.com']);

        $result = $validator->validate('https://example.com/users');

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function validateIsCaseInsensitiveForHosts(): void
    {
        $validator = new TargetUrlValidator(['API.EXAMPLE.COM']);

        $result = $validator->validate('https://api.example.com/users');

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validateAcceptsUrlWithPort(): void
    {
        $validator = new TargetUrlValidator();

        $result = $validator->validate('https://api.example.com:8443/users');

        self::assertTrue($result->isValid);
    }

    #[Test]
    public function validateAcceptsUrlWithQueryString(): void
    {
        $validator = new TargetUrlValidator();

        $result = $validator->validate('https://api.example.com/users?page=1&limit=10');

        self::assertTrue($result->isValid);
    }
}
