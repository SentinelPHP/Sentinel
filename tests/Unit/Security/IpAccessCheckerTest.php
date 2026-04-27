<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\IpAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(IpAccessChecker::class)]
final class IpAccessCheckerTest extends TestCase
{
    #[Test]
    public function isAllowedReturnsFalseWhenClientIpIsNull(): void
    {
        $checker = new IpAccessChecker();
        $request = new Request();

        self::assertFalse($checker->isAllowed($request));
    }

    #[Test]
    #[DataProvider('privateIpProvider')]
    public function isAllowedReturnsTrueForPrivateIpsWithDefaultConfig(string $ip): void
    {
        $checker = new IpAccessChecker();
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);

        self::assertTrue($checker->isAllowed($request));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateIpProvider(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'loopback other' => ['127.0.0.5'];
        yield 'class A private' => ['10.0.0.1'];
        yield 'class A private other' => ['10.255.255.255'];
        yield 'class B private' => ['172.16.0.1'];
        yield 'class B private other' => ['172.31.255.255'];
        yield 'class C private' => ['192.168.0.1'];
        yield 'class C private other' => ['192.168.255.255'];
        yield 'link local' => ['169.254.1.1'];
        yield 'IPv6 loopback' => ['::1'];
    }

    #[Test]
    #[DataProvider('publicIpProvider')]
    public function isAllowedReturnsFalseForPublicIpsWithDefaultConfig(string $ip): void
    {
        $checker = new IpAccessChecker();
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);

        self::assertFalse($checker->isAllowed($request));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicIpProvider(): iterable
    {
        yield 'public IP 1' => ['8.8.8.8'];
        yield 'public IP 2' => ['1.1.1.1'];
        yield 'public IP 3' => ['203.0.113.1'];
        yield 'class B boundary' => ['172.32.0.1'];
        yield 'IPv6 public' => ['2001:4860:4860::8888'];
    }

    #[Test]
    public function isAllowedUsesCustomAllowedIps(): void
    {
        $checker = new IpAccessChecker(['8.8.8.8', '1.1.1.0/24']);

        $request1 = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '8.8.8.8']);
        $request2 = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.1.1.50']);
        $request3 = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);

        self::assertTrue($checker->isAllowed($request1));
        self::assertTrue($checker->isAllowed($request2));
        self::assertFalse($checker->isAllowed($request3));
    }

    #[Test]
    public function isAllowedIpChecksIpDirectly(): void
    {
        $checker = new IpAccessChecker();

        self::assertTrue($checker->isAllowedIp('127.0.0.1'));
        self::assertTrue($checker->isAllowedIp('10.0.0.1'));
        self::assertFalse($checker->isAllowedIp('8.8.8.8'));
    }

    #[Test]
    public function getAllowedRangesReturnsConfiguredRanges(): void
    {
        $ranges = ['10.0.0.0/8', '192.168.0.0/16'];
        $checker = new IpAccessChecker($ranges);

        self::assertSame($ranges, $checker->getAllowedRanges());
    }

    #[Test]
    public function getAllowedRangesReturnsDefaultRangesWhenNullPassed(): void
    {
        $checker = new IpAccessChecker(null);

        self::assertSame(IpAccessChecker::getDefaultPrivateRanges(), $checker->getAllowedRanges());
    }

    #[Test]
    public function getDefaultPrivateRangesReturnsExpectedRanges(): void
    {
        $ranges = IpAccessChecker::getDefaultPrivateRanges();

        self::assertContains('127.0.0.0/8', $ranges);
        self::assertContains('10.0.0.0/8', $ranges);
        self::assertContains('172.16.0.0/12', $ranges);
        self::assertContains('192.168.0.0/16', $ranges);
        self::assertContains('169.254.0.0/16', $ranges);
        self::assertContains('::1/128', $ranges);
        self::assertContains('fc00::/7', $ranges);
        self::assertContains('fe80::/10', $ranges);
    }

    #[Test]
    public function isAllowedHandlesCidrRangesCorrectly(): void
    {
        $checker = new IpAccessChecker(['203.0.113.0/24']);

        $request1 = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.1']);
        $request2 = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.255']);
        $request3 = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.114.1']);

        self::assertTrue($checker->isAllowed($request1));
        self::assertTrue($checker->isAllowed($request2));
        self::assertFalse($checker->isAllowed($request3));
    }

    #[Test]
    public function isAllowedHandlesIpv6CidrRanges(): void
    {
        $checker = new IpAccessChecker(['2001:db8::/32']);

        $request1 = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '2001:db8::1']);
        $request2 = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '2001:db8:ffff::1']);
        $request3 = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '2001:db9::1']);

        self::assertTrue($checker->isAllowed($request1));
        self::assertTrue($checker->isAllowed($request2));
        self::assertFalse($checker->isAllowed($request3));
    }

    #[Test]
    public function isAllowedWithEmptyArrayDeniesAll(): void
    {
        $checker = new IpAccessChecker([]);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        self::assertFalse($checker->isAllowed($request));
    }
}
