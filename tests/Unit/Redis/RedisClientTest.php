<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redis;

use App\Redis\RedisClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedisClient::class)]
final class RedisClientTest extends TestCase
{
    #[Test]
    public function constructorAcceptsRedisUrl(): void
    {
        $client = new RedisClient('redis://localhost:6379');

        self::assertInstanceOf(RedisClient::class, $client);
    }

    #[Test]
    public function constructorAcceptsRedisUrlWithDatabase(): void
    {
        $client = new RedisClient('redis://localhost:6379/1');

        self::assertInstanceOf(RedisClient::class, $client);
    }

    #[Test]
    public function constructorAcceptsRedisUrlWithPassword(): void
    {
        $client = new RedisClient('redis://:password@localhost:6379');

        self::assertInstanceOf(RedisClient::class, $client);
    }
}
