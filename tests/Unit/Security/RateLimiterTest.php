<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Redis\RedisClientInterface;
use App\Security\RateLimiter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimiter::class)]
#[AllowMockObjectsWithoutExpectations]
final class RateLimiterTest extends TestCase
{
    private RedisClientInterface&MockObject $redisClient;

    protected function setUp(): void
    {
        $this->redisClient = $this->createMock(RedisClientInterface::class);
    }

    #[Test]
    public function allowsRequestWhenNoRedisClient(): void
    {
        $limiter = new RateLimiter(null);

        $result = $limiter->isAllowed('test-identifier');

        self::assertTrue($result->isAllowed);
        self::assertSame(1000, $result->limit);
        self::assertSame(1000, $result->remaining);
    }

    #[Test]
    public function allowsRequestWhenUnderLimit(): void
    {
        $this->redisClient->method('get')->willReturn('5');
        $this->redisClient->method('incr')->willReturn(6);

        $limiter = new RateLimiter($this->redisClient, maxRequests: 100);

        $result = $limiter->isAllowed('test-identifier');

        self::assertTrue($result->isAllowed);
        self::assertSame(100, $result->limit);
        self::assertSame(94, $result->remaining);
    }

    #[Test]
    public function deniesRequestWhenAtLimit(): void
    {
        $this->redisClient->method('get')->willReturn('100');

        $limiter = new RateLimiter($this->redisClient, maxRequests: 100, windowSeconds: 60);

        $result = $limiter->isAllowed('test-identifier');

        self::assertFalse($result->isAllowed);
        self::assertSame(100, $result->limit);
        self::assertSame(0, $result->remaining);
        self::assertSame(60, $result->retryAfterSeconds);
    }

    #[Test]
    public function setsExpiryOnFirstRequest(): void
    {
        $this->redisClient->method('get')->willReturn(null);
        $this->redisClient->method('incr')->willReturn(1);
        $this->redisClient->expects(self::once())
            ->method('setex')
            ->with(
                self::stringContains('rate_limit:'),
                60,
                '1'
            );

        $limiter = new RateLimiter($this->redisClient, windowSeconds: 60);

        $limiter->isAllowed('test-identifier');
    }

    #[Test]
    public function allowsAuthFailureWhenNoRedisClient(): void
    {
        $limiter = new RateLimiter(null);

        $result = $limiter->isAuthFailureAllowed('192.168.1.1');

        self::assertTrue($result->isAllowed);
        self::assertSame(10, $result->limit);
    }

    #[Test]
    public function deniesAuthFailureWhenAtLimit(): void
    {
        $this->redisClient->method('get')->willReturn('10');

        $limiter = new RateLimiter($this->redisClient);

        $result = $limiter->isAuthFailureAllowed('192.168.1.1');

        self::assertFalse($result->isAllowed);
        self::assertSame(10, $result->limit);
        self::assertSame(300, $result->retryAfterSeconds);
    }

    #[Test]
    public function recordsAuthFailure(): void
    {
        $this->redisClient->method('incr')->willReturn(1);
        $this->redisClient->expects(self::once())
            ->method('setex')
            ->with(
                self::stringContains('auth_fail:192.168.1.1'),
                300,
                '1'
            );

        $limiter = new RateLimiter($this->redisClient);

        $limiter->recordAuthFailure('192.168.1.1');
    }

    #[Test]
    public function clearsAuthFailures(): void
    {
        $this->redisClient->expects(self::once())
            ->method('del')
            ->with(self::stringContains('auth_fail:192.168.1.1'));

        $limiter = new RateLimiter($this->redisClient);

        $limiter->clearAuthFailures('192.168.1.1');
    }

    #[Test]
    public function recordAuthFailureNoOpWithoutRedis(): void
    {
        $limiter = new RateLimiter(null);

        // Should not throw - if we get here without exception, test passes
        $limiter->recordAuthFailure('192.168.1.1');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function clearAuthFailuresNoOpWithoutRedis(): void
    {
        $limiter = new RateLimiter(null);

        // Should not throw - if we get here without exception, test passes
        $limiter->clearAuthFailures('192.168.1.1');

        $this->expectNotToPerformAssertions();
    }
}
