<?php

declare(strict_types=1);

namespace SentinelPHP\Drift\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Drift\Diff\DiffEntry;
use SentinelPHP\Drift\Diff\DiffResult;
use SentinelPHP\Drift\Diff\JsonDiff;

#[CoversClass(JsonDiff::class)]
#[CoversClass(DiffResult::class)]
#[CoversClass(DiffEntry::class)]
final class JsonDiffTest extends TestCase
{
    private JsonDiff $diff;

    protected function setUp(): void
    {
        $this->diff = new JsonDiff();
    }

    #[Test]
    public function itReturnsEmptyResultForIdenticalArrays(): void
    {
        $data = ['name' => 'test', 'value' => 123];

        $result = $this->diff->generateDiff($data, $data);

        self::assertFalse($result->hasDifferences());
        self::assertSame(0, $result->getTotalCount());
        self::assertEmpty($result->added);
        self::assertEmpty($result->removed);
        self::assertEmpty($result->changed);
    }

    #[Test]
    public function itDetectsAddedFields(): void
    {
        $expected = ['name' => 'test'];
        $actual = ['name' => 'test', 'age' => 25];

        $result = $this->diff->generateDiff($expected, $actual);

        self::assertTrue($result->hasDifferences());
        self::assertCount(1, $result->added);
        self::assertEmpty($result->removed);
        self::assertEmpty($result->changed);
        self::assertSame('age', $result->added[0]->path);
        self::assertSame(25, $result->added[0]->actualValue);
    }

    #[Test]
    public function itDetectsRemovedFields(): void
    {
        $expected = ['name' => 'test', 'age' => 25];
        $actual = ['name' => 'test'];

        $result = $this->diff->generateDiff($expected, $actual);

        self::assertTrue($result->hasDifferences());
        self::assertEmpty($result->added);
        self::assertCount(1, $result->removed);
        self::assertEmpty($result->changed);
        self::assertSame('age', $result->removed[0]->path);
        self::assertSame(25, $result->removed[0]->expectedValue);
    }

    #[Test]
    public function itDetectsChangedFields(): void
    {
        $expected = ['name' => 'test', 'value' => 100];
        $actual = ['name' => 'test', 'value' => 200];

        $result = $this->diff->generateDiff($expected, $actual);

        self::assertTrue($result->hasDifferences());
        self::assertEmpty($result->added);
        self::assertEmpty($result->removed);
        self::assertCount(1, $result->changed);
        self::assertSame('value', $result->changed[0]->path);
        self::assertSame(100, $result->changed[0]->expectedValue);
        self::assertSame(200, $result->changed[0]->actualValue);
    }

    #[Test]
    public function itHandlesNestedObjects(): void
    {
        $expected = ['user' => ['name' => 'John', 'email' => 'john@example.com']];
        $actual = ['user' => ['name' => 'Jane', 'email' => 'john@example.com']];

        $result = $this->diff->generateDiff($expected, $actual);

        self::assertTrue($result->hasDifferences());
        self::assertCount(1, $result->changed);
        self::assertSame('user.name', $result->changed[0]->path);
    }

    #[Test]
    public function itHandlesDeeplyNestedObjects(): void
    {
        $expected = ['level1' => ['level2' => ['level3' => ['value' => 'old']]]];
        $actual = ['level1' => ['level2' => ['level3' => ['value' => 'new']]]];

        $result = $this->diff->generateDiff($expected, $actual);

        self::assertTrue($result->hasDifferences());
        self::assertCount(1, $result->changed);
        self::assertSame('level1.level2.level3.value', $result->changed[0]->path);
    }

    #[Test]
    public function itHandlesNullExpected(): void
    {
        $actual = ['name' => 'test'];

        $result = $this->diff->generateDiff(null, $actual);

        self::assertTrue($result->hasDifferences());
        self::assertCount(1, $result->added);
        self::assertSame('name', $result->added[0]->path);
    }

    #[Test]
    public function itHandlesNullActual(): void
    {
        $expected = ['name' => 'test'];

        $result = $this->diff->generateDiff($expected, null);

        self::assertTrue($result->hasDifferences());
        self::assertCount(1, $result->removed);
        self::assertSame('name', $result->removed[0]->path);
    }

    #[Test]
    public function itHandlesBothNull(): void
    {
        $result = $this->diff->generateDiff(null, null);

        self::assertFalse($result->hasDifferences());
        self::assertSame(0, $result->getTotalCount());
    }

    #[Test]
    public function itHandlesEmptyArrays(): void
    {
        $result = $this->diff->generateDiff([], []);

        self::assertFalse($result->hasDifferences());
    }

    #[Test]
    public function itHandlesArrayValues(): void
    {
        $expected = ['tags' => ['a', 'b']];
        $actual = ['tags' => ['a', 'b', 'c']];

        $result = $this->diff->generateDiff($expected, $actual);

        self::assertTrue($result->hasDifferences());
    }

    #[Test]
    public function itHandlesTypeChanges(): void
    {
        $expected = ['value' => '123'];
        $actual = ['value' => 123];

        $result = $this->diff->generateDiff($expected, $actual);

        self::assertTrue($result->hasDifferences());
        self::assertCount(1, $result->changed);
    }

    #[Test]
    public function diffResultToArrayWorks(): void
    {
        $expected = ['name' => 'old'];
        $actual = ['name' => 'new', 'age' => 25];

        $result = $this->diff->generateDiff($expected, $actual);
        $array = $result->toArray();

        self::assertArrayHasKey('added', $array);
        self::assertArrayHasKey('removed', $array);
        self::assertArrayHasKey('changed', $array);
        self::assertArrayHasKey('hasDifferences', $array);
        self::assertArrayHasKey('totalCount', $array);
        self::assertTrue($array['hasDifferences']);
        self::assertSame(2, $array['totalCount']);
    }

    #[Test]
    public function diffEntryToArrayWorks(): void
    {
        $expected = ['name' => 'old'];
        $actual = ['name' => 'new'];

        $result = $this->diff->generateDiff($expected, $actual);
        $entryArray = $result->changed[0]->toArray();

        self::assertArrayHasKey('path', $entryArray);
        self::assertArrayHasKey('expectedValue', $entryArray);
        self::assertArrayHasKey('actualValue', $entryArray);
        self::assertSame('name', $entryArray['path']);
        self::assertSame('old', $entryArray['expectedValue']);
        self::assertSame('new', $entryArray['actualValue']);
    }
}
