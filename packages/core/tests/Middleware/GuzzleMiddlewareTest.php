<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Tests\Middleware;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Core\Client\IdGeneratorInterface;
use SentinelPHP\Core\Config\InterceptorConfig;
use SentinelPHP\Core\Middleware\GuzzleMiddleware;
use SentinelPHP\Core\Record\ApiCallRecord;
use SentinelPHP\Core\SentinelInterceptor;
use SentinelPHP\Core\Storage\StorageInterface;

#[CoversClass(GuzzleMiddleware::class)]
#[AllowMockObjectsWithoutExpectations]
final class GuzzleMiddlewareTest extends TestCase
{
    #[Test]
    public function it_intercepts_guzzle_requests(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())
            ->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $config = new InterceptorConfig(redactPii: false);
        $interceptor = new SentinelInterceptor($storage, $config);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"id":1}'),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(GuzzleMiddleware::create($interceptor));

        $client = new Client(['handler' => $stack]);
        $response = $client->get('https://api.example.com/users/1');

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($capturedRecord);
        self::assertSame('GET', $capturedRecord->method);
        self::assertSame('https://api.example.com/users/1', $capturedRecord->url);
        self::assertSame(200, $capturedRecord->statusCode);
        self::assertSame('{"id":1}', $capturedRecord->responseBody);
    }

    #[Test]
    public function it_captures_post_request_body(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $config = new InterceptorConfig(redactPii: false);
        $interceptor = new SentinelInterceptor($storage, $config);

        $mock = new MockHandler([
            new Response(201, [], '{"id":1,"name":"John"}'),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(GuzzleMiddleware::create($interceptor));

        $client = new Client(['handler' => $stack]);
        $client->post('https://api.example.com/users', [
            'json' => ['name' => 'John'],
        ]);

        self::assertNotNull($capturedRecord);
        self::assertSame('POST', $capturedRecord->method);
        self::assertSame(201, $capturedRecord->statusCode);
    }

    #[Test]
    public function it_uses_id_generator(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $idGenerator = $this->createMock(IdGeneratorInterface::class);
        $idGenerator->method('generate')->willReturn('guzzle-id-456');

        $interceptor = new SentinelInterceptor($storage, InterceptorConfig::minimal());

        $mock = new MockHandler([new Response(200)]);
        $stack = HandlerStack::create($mock);
        $stack->push(GuzzleMiddleware::create($interceptor, $idGenerator));

        $client = new Client(['handler' => $stack]);
        $client->get('https://api.example.com/test');

        self::assertNotNull($capturedRecord);
        self::assertSame('guzzle-id-456', $capturedRecord->id);
    }

    #[Test]
    public function it_measures_latency(): void
    {
        $capturedRecord = null;

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $interceptor = new SentinelInterceptor($storage, InterceptorConfig::minimal());

        $mock = new MockHandler([new Response(200)]);
        $stack = HandlerStack::create($mock);
        $stack->push(GuzzleMiddleware::create($interceptor));

        $client = new Client(['handler' => $stack]);
        $client->get('https://api.example.com/test');

        self::assertNotNull($capturedRecord);
        self::assertGreaterThanOrEqual(0, $capturedRecord->latencyMs);
    }

    #[Test]
    public function it_allows_response_body_to_be_read_after_interception(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $interceptor = new SentinelInterceptor($storage, InterceptorConfig::minimal());

        $mock = new MockHandler([
            new Response(200, [], '{"data":"important"}'),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(GuzzleMiddleware::create($interceptor));

        $client = new Client(['handler' => $stack]);
        $response = $client->get('https://api.example.com/test');

        // Body should still be readable
        self::assertSame('{"data":"important"}', (string) $response->getBody());
    }

    #[Test]
    public function it_propagates_network_errors_without_intercepting(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::never())->method('store');

        $interceptor = new SentinelInterceptor($storage, InterceptorConfig::minimal());

        $request = new Request('GET', 'https://api.example.com/test');
        $exception = new RequestException('Connection refused', $request);

        $mock = new MockHandler([$exception]);
        $stack = HandlerStack::create($mock);
        $stack->push(GuzzleMiddleware::create($interceptor));

        $client = new Client(['handler' => $stack]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Connection refused');

        $client->get('https://api.example.com/test');
    }
}
