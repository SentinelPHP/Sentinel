<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Alert;

use App\Redis\RedisClientInterface;
use App\Service\Alert\CircuitBreaker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CircuitBreaker::class)]
#[AllowMockObjectsWithoutExpectations]
final class CircuitBreakerTest extends TestCase
{
    private RedisClientInterface&MockObject $redisClient;

    protected function setUp(): void
    {
        $this->redisClient = $this->createMock(RedisClientInterface::class);
    }

    #[Test]
    public function isAvailableReturnsTrueWhenNoRedisClient(): void
    {
        $breaker = new CircuitBreaker(null);

        self::assertTrue($breaker->isAvailable('test-service'));
    }

    #[Test]
    public function isAvailableReturnsTrueWhenCircuitIsClosed(): void
    {
        $this->redisClient->method('get')->willReturn(null);

        $breaker = new CircuitBreaker($this->redisClient);

        self::assertTrue($breaker->isAvailable('test-service'));
    }

    #[Test]
    public function isAvailableReturnsFalseWhenCircuitIsOpenAndNotRecoveryTime(): void
    {
        $this->redisClient->method('get')
            ->willReturnCallback(function (string $key): ?string {
                if (str_contains($key, ':state')) {
                    return 'open';
                }
                if (str_contains($key, ':last_failure')) {
                    return (string) time();
                }
                return null;
            });

        $breaker = new CircuitBreaker($this->redisClient, recoveryTimeSeconds: 60);

        self::assertFalse($breaker->isAvailable('test-service'));
    }

    #[Test]
    public function isAvailableReturnsTrueWhenCircuitIsOpenAndRecoveryTimeElapsed(): void
    {
        $this->redisClient->method('get')
            ->willReturnCallback(function (string $key): ?string {
                if (str_contains($key, ':state')) {
                    return 'open';
                }
                if (str_contains($key, ':last_failure')) {
                    return (string) (time() - 120);
                }
                return null;
            });

        $this->redisClient->expects($this->once())
            ->method('setex')
            ->with(
                $this->stringContains(':state'),
                $this->anything(),
                'half_open'
            );

        $breaker = new CircuitBreaker($this->redisClient, recoveryTimeSeconds: 60);

        self::assertTrue($breaker->isAvailable('test-service'));
    }

    #[Test]
    public function isAvailableReturnsTrueWhenCircuitIsHalfOpen(): void
    {
        $this->redisClient->method('get')
            ->willReturnCallback(function (string $key): ?string {
                if (str_contains($key, ':state')) {
                    return 'half_open';
                }
                return null;
            });

        $breaker = new CircuitBreaker($this->redisClient);

        self::assertTrue($breaker->isAvailable('test-service'));
    }

    #[Test]
    public function recordSuccessResetsCircuitWhenHalfOpen(): void
    {
        $this->redisClient->method('get')
            ->willReturnCallback(function (string $key): ?string {
                if (str_contains($key, ':state')) {
                    return 'half_open';
                }
                return null;
            });

        $this->redisClient->expects($this->exactly(3))
            ->method('del');

        $breaker = new CircuitBreaker($this->redisClient);
        $breaker->recordSuccess('test-service');
    }

    #[Test]
    public function recordSuccessDoesNothingWhenClosed(): void
    {
        $this->redisClient->method('get')->willReturn(null);
        $this->redisClient->expects($this->never())->method('del');

        $breaker = new CircuitBreaker($this->redisClient);
        $breaker->recordSuccess('test-service');
    }

    #[Test]
    public function recordFailureIncrementsCounterAndSetsExpiry(): void
    {
        $this->redisClient->method('get')->willReturn(null);
        $this->redisClient->method('incr')->willReturn(1);

        $this->redisClient->expects($this->once())
            ->method('setex')
            ->with(
                $this->stringContains(':failures'),
                120,
                '1'
            );

        $breaker = new CircuitBreaker($this->redisClient, failureWindowSeconds: 120);
        $breaker->recordFailure('test-service');
    }

    #[Test]
    public function recordFailureTripsCircuitWhenThresholdReached(): void
    {
        $this->redisClient->method('get')->willReturn(null);
        $this->redisClient->method('incr')->willReturn(5);

        $setexCalls = [];
        $this->redisClient->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use (&$setexCalls): void {
                $setexCalls[] = ['key' => $key, 'value' => $value];
            });

        $breaker = new CircuitBreaker($this->redisClient, failureThreshold: 5);
        $breaker->recordFailure('test-service');

        $stateSet = array_filter($setexCalls, fn ($c) => str_contains($c['key'], ':state'));
        self::assertNotEmpty($stateSet);
        self::assertSame('open', array_values($stateSet)[0]['value']);
    }

    #[Test]
    public function recordFailureTripsCircuitImmediatelyWhenHalfOpen(): void
    {
        $this->redisClient->method('get')
            ->willReturnCallback(function (string $key): ?string {
                if (str_contains($key, ':state')) {
                    return 'half_open';
                }
                return null;
            });

        $this->redisClient->expects($this->never())->method('incr');

        $setexCalls = [];
        $this->redisClient->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use (&$setexCalls): void {
                $setexCalls[] = ['key' => $key, 'value' => $value];
            });

        $breaker = new CircuitBreaker($this->redisClient);
        $breaker->recordFailure('test-service');

        $stateSet = array_filter($setexCalls, fn ($c) => str_contains($c['key'], ':state'));
        self::assertNotEmpty($stateSet);
    }

    #[Test]
    public function getStateReturnsClosedWhenNoRedisClient(): void
    {
        $breaker = new CircuitBreaker(null);

        self::assertSame('closed', $breaker->getState('test-service'));
    }

    #[Test]
    public function getStateReturnsClosedWhenNoStateStored(): void
    {
        $this->redisClient->method('get')->willReturn(null);

        $breaker = new CircuitBreaker($this->redisClient);

        self::assertSame('closed', $breaker->getState('test-service'));
    }

    #[Test]
    public function getStateReturnsStoredState(): void
    {
        $this->redisClient->method('get')
            ->willReturnCallback(function (string $key): ?string {
                if (str_contains($key, ':state')) {
                    return 'open';
                }
                return null;
            });

        $breaker = new CircuitBreaker($this->redisClient);

        self::assertSame('open', $breaker->getState('test-service'));
    }

    #[Test]
    public function isOpenReturnsTrueWhenStateIsOpen(): void
    {
        $this->redisClient->method('get')
            ->willReturnCallback(function (string $key): ?string {
                if (str_contains($key, ':state')) {
                    return 'open';
                }
                return null;
            });

        $breaker = new CircuitBreaker($this->redisClient);

        self::assertTrue($breaker->isOpen('test-service'));
    }

    #[Test]
    public function isOpenReturnsFalseWhenStateIsClosed(): void
    {
        $this->redisClient->method('get')->willReturn(null);

        $breaker = new CircuitBreaker($this->redisClient);

        self::assertFalse($breaker->isOpen('test-service'));
    }

    #[Test]
    public function resetClearsAllKeys(): void
    {
        $deletedKeys = [];
        $this->redisClient->method('del')
            ->willReturnCallback(function (string $key) use (&$deletedKeys): bool {
                $deletedKeys[] = $key;
                return true;
            });

        $breaker = new CircuitBreaker($this->redisClient);
        $breaker->reset('test-service');

        self::assertCount(3, $deletedKeys);
        self::assertTrue(
            count(array_filter($deletedKeys, fn ($k) => str_contains($k, ':state'))) === 1
        );
        self::assertTrue(
            count(array_filter($deletedKeys, fn ($k) => str_contains($k, ':failures'))) === 1
        );
        self::assertTrue(
            count(array_filter($deletedKeys, fn ($k) => str_contains($k, ':last_failure'))) === 1
        );
    }

    #[Test]
    public function resetDoesNothingWhenNoRedisClient(): void
    {
        $breaker = new CircuitBreaker(null);
        $breaker->reset('test-service');

        $this->expectNotToPerformAssertions();
    }
}
