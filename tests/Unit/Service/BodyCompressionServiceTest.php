<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BodyCompressionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BodyCompressionService::class)]
final class BodyCompressionServiceTest extends TestCase
{
    private BodyCompressionService $service;

    protected function setUp(): void
    {
        $this->service = new BodyCompressionService();
    }

    #[Test]
    public function itCompressesAndDecompressesData(): void
    {
        $original = '{"name":"John","email":"john@example.com","data":{"nested":"value"}}';

        $compressed = $this->service->compress($original);
        $decompressed = $this->service->decompress($compressed);

        self::assertSame($original, $decompressed);
        self::assertNotSame($original, $compressed);
        self::assertTrue($this->service->isCompressed($compressed));
    }

    #[Test]
    public function itReturnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', $this->service->compress(''));
        self::assertSame('', $this->service->decompress(''));
    }

    #[Test]
    public function itPrefixesCompressedDataWithGzip(): void
    {
        $compressed = $this->service->compress('test data');

        self::assertStringStartsWith('gzip:', $compressed);
    }

    #[Test]
    public function itReturnsUncompressedDataWhenNotPrefixed(): void
    {
        $plainData = 'plain text without compression';

        self::assertSame($plainData, $this->service->decompress($plainData));
    }

    #[Test]
    public function itIdentifiesCompressedData(): void
    {
        $compressed = $this->service->compress('test');

        self::assertTrue($this->service->isCompressed($compressed));
        self::assertFalse($this->service->isCompressed('plain text'));
        self::assertFalse($this->service->isCompressed(''));
    }

    #[Test]
    public function itHandlesLargePayloads(): void
    {
        $largePayload = str_repeat('{"key":"value","number":12345},', 10000);

        $compressed = $this->service->compress($largePayload);
        $decompressed = $this->service->decompress($compressed);

        self::assertSame($largePayload, $decompressed);
        self::assertLessThan(strlen($largePayload), strlen($compressed));
    }

    #[Test]
    public function itHandlesUnicodeContent(): void
    {
        $unicodeData = '{"message":"Hello 世界 🌍","emoji":"🚀✨"}';

        $compressed = $this->service->compress($unicodeData);
        $decompressed = $this->service->decompress($compressed);

        self::assertSame($unicodeData, $decompressed);
    }

    #[Test]
    public function itHandlesBinaryLikeContent(): void
    {
        $binaryLike = base64_encode(random_bytes(100));

        $compressed = $this->service->compress($binaryLike);
        $decompressed = $this->service->decompress($compressed);

        self::assertSame($binaryLike, $decompressed);
    }

    #[Test]
    #[DataProvider('jsonPayloadProvider')]
    public function itCompressesVariousJsonPayloads(string $json): void
    {
        $compressed = $this->service->compress($json);
        $decompressed = $this->service->decompress($compressed);

        self::assertSame($json, $decompressed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function jsonPayloadProvider(): iterable
    {
        yield 'simple object' => ['{"key":"value"}'];
        yield 'array' => ['[1,2,3,4,5]'];
        yield 'nested object' => ['{"user":{"name":"John","address":{"city":"NYC"}}}'];
        yield 'null value' => ['{"value":null}'];
        yield 'boolean values' => ['{"active":true,"deleted":false}'];
        yield 'numeric values' => ['{"int":42,"float":3.14,"negative":-100}'];
    }
}
