<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Tests\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Core\Client\UuidIdGenerator;

#[CoversClass(UuidIdGenerator::class)]
final class UuidIdGeneratorTest extends TestCase
{
    #[Test]
    public function it_generates_valid_uuid_v4(): void
    {
        $generator = new UuidIdGenerator();
        $uuid = $generator->generate();

        // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        // where y is one of 8, 9, a, or b
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        self::assertMatchesRegularExpression($pattern, $uuid);
    }

    #[Test]
    public function it_generates_unique_ids(): void
    {
        $generator = new UuidIdGenerator();

        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $generator->generate();
        }

        $uniqueIds = array_unique($ids);

        self::assertCount(100, $uniqueIds, 'All generated IDs should be unique');
    }

    #[Test]
    public function it_generates_correct_length(): void
    {
        $generator = new UuidIdGenerator();
        $uuid = $generator->generate();

        self::assertSame(36, strlen($uuid));
    }
}
