<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validation;

use App\Validation\HostMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HostMatcher::class)]
final class HostMatcherTest extends TestCase
{
    #[Test]
    public function matchesReturnsTrueWhenPatternsEmpty(): void
    {
        self::assertTrue(HostMatcher::matches('any.host.com', []));
    }

    #[Test]
    public function matchesReturnsTrueForGlobalWildcard(): void
    {
        self::assertTrue(HostMatcher::matches('any.host.com', ['*']));
        self::assertTrue(HostMatcher::matches('api.stripe.com', ['*']));
        self::assertTrue(HostMatcher::matches('localhost', ['*']));
    }

    #[Test]
    public function matchesReturnsTrueForExactMatch(): void
    {
        self::assertTrue(HostMatcher::matches('api.example.com', ['api.example.com']));
    }

    #[Test]
    public function matchesReturnsFalseForNoMatch(): void
    {
        self::assertFalse(HostMatcher::matches('api.other.com', ['api.example.com']));
    }

    #[Test]
    public function matchesSupportsWildcardPatterns(): void
    {
        self::assertTrue(HostMatcher::matches('api.example.com', ['*.example.com']));
        self::assertTrue(HostMatcher::matches('admin.example.com', ['*.example.com']));
    }

    #[Test]
    public function matchesWildcardDoesNotMatchRootDomain(): void
    {
        self::assertFalse(HostMatcher::matches('example.com', ['*.example.com']));
    }

    #[Test]
    public function matchesIsCaseInsensitive(): void
    {
        self::assertTrue(HostMatcher::matches('API.EXAMPLE.COM', ['api.example.com']));
        self::assertTrue(HostMatcher::matches('api.example.com', ['API.EXAMPLE.COM']));
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('matchingPatternsProvider')]
    public function matchesWorksWithVariousPatterns(string $host, array $patterns, bool $expected): void
    {
        /** @var list<string> $patterns */
        self::assertSame($expected, HostMatcher::matches($host, $patterns));
    }

    /**
     * @return iterable<string, array{host: string, patterns: list<string>, expected: bool}>
     */
    public static function matchingPatternsProvider(): iterable
    {
        yield 'single exact match' => [
            'host' => 'api.stripe.com',
            'patterns' => ['api.stripe.com'],
            'expected' => true,
        ];

        yield 'multiple patterns first matches' => [
            'host' => 'api.stripe.com',
            'patterns' => ['api.stripe.com', 'api.paypal.com'],
            'expected' => true,
        ];

        yield 'multiple patterns second matches' => [
            'host' => 'api.paypal.com',
            'patterns' => ['api.stripe.com', 'api.paypal.com'],
            'expected' => true,
        ];

        yield 'wildcard matches subdomain' => [
            'host' => 'api.stripe.com',
            'patterns' => ['*.stripe.com'],
            'expected' => true,
        ];

        yield 'wildcard matches deep subdomain' => [
            'host' => 'v1.api.stripe.com',
            'patterns' => ['*.stripe.com'],
            'expected' => true,
        ];

        yield 'mixed patterns with wildcard' => [
            'host' => 'api.openai.com',
            'patterns' => ['api.stripe.com', '*.openai.com'],
            'expected' => true,
        ];

        yield 'no matching pattern' => [
            'host' => 'api.malicious.com',
            'patterns' => ['api.stripe.com', '*.openai.com'],
            'expected' => false,
        ];

        yield 'case insensitive exact' => [
            'host' => 'API.STRIPE.COM',
            'patterns' => ['api.stripe.com'],
            'expected' => true,
        ];

        yield 'case insensitive wildcard' => [
            'host' => 'API.STRIPE.COM',
            'patterns' => ['*.STRIPE.COM'],
            'expected' => true,
        ];
    }
}
