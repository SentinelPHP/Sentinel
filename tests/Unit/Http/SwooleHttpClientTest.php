<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\HttpClientException;
use App\Http\SwooleHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SwooleHttpClient::class)]
final class SwooleHttpClientTest extends TestCase
{
    #[Test]
    public function throwsExceptionForInvalidUrl(): void
    {
        $client = new SwooleHttpClient();

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('Invalid URL');

        $client->request('GET', 'not-a-valid-url');
    }

    #[Test]
    public function throwsExceptionForUrlWithoutHost(): void
    {
        $client = new SwooleHttpClient();

        $this->expectException(HttpClientException::class);

        $client->request('GET', '/path/only');
    }

    #[Test]
    public function constructsWithDefaultTimeouts(): void
    {
        $client = new SwooleHttpClient();

        $reflection = new \ReflectionClass($client);

        $timeoutProperty = $reflection->getProperty('timeout');
        $connectTimeoutProperty = $reflection->getProperty('connectTimeout');

        self::assertSame(30.0, $timeoutProperty->getValue($client));
        self::assertSame(10.0, $connectTimeoutProperty->getValue($client));
    }

    #[Test]
    public function constructsWithCustomTimeouts(): void
    {
        $client = new SwooleHttpClient(timeout: 60.0, connectTimeout: 5.0);

        $reflection = new \ReflectionClass($client);

        $timeoutProperty = $reflection->getProperty('timeout');
        $connectTimeoutProperty = $reflection->getProperty('connectTimeout');

        self::assertSame(60.0, $timeoutProperty->getValue($client));
        self::assertSame(5.0, $connectTimeoutProperty->getValue($client));
    }

    #[Test]
    public function buildsPathWithQueryString(): void
    {
        $client = new SwooleHttpClient();
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('buildPath');

        $parsed = parse_url('https://example.com/api/users?page=1&limit=10');
        $path = $method->invoke($client, $parsed);

        self::assertSame('/api/users?page=1&limit=10', $path);
    }

    #[Test]
    public function buildsPathWithFragment(): void
    {
        $client = new SwooleHttpClient();
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('buildPath');

        $parsed = parse_url('https://example.com/docs#section-1');
        $path = $method->invoke($client, $parsed);

        self::assertSame('/docs#section-1', $path);
    }

    #[Test]
    public function buildsPathDefaultsToSlash(): void
    {
        $client = new SwooleHttpClient();
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('buildPath');

        $parsed = parse_url('https://example.com');
        $path = $method->invoke($client, $parsed);

        self::assertSame('/', $path);
    }

    #[Test]
    public function normalizesHeaders(): void
    {
        $client = new SwooleHttpClient();
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('normalizeHeaders');

        $headers = [
            'Content-Type' => 'application/json',
            'X-Array-Header' => ['value1', 'value2'],
            'X-Int-Header' => 123,
        ];

        $normalized = $method->invoke($client, $headers);
        self::assertIsArray($normalized);

        /** @var array<string, string> $normalized */
        self::assertSame('application/json', $normalized['Content-Type']);
        self::assertSame('value1', $normalized['X-Array-Header']);
        self::assertSame('123', $normalized['X-Int-Header']);
    }

}
