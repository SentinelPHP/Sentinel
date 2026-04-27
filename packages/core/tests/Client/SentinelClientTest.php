<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Tests\Client;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use SentinelPHP\Core\Client\IdGeneratorInterface;
use SentinelPHP\Core\Client\SentinelClient;
use SentinelPHP\Core\Config\InterceptorConfig;
use SentinelPHP\Core\Record\ApiCallRecord;
use SentinelPHP\Core\SentinelInterceptor;
use SentinelPHP\Core\Storage\StorageInterface;

#[CoversClass(SentinelClient::class)]
final class SentinelClientTest extends TestCase
{
    #[Test]
    public function it_forwards_request_to_inner_client(): void
    {
        $request = new Request('GET', 'https://api.example.com/users');
        $expectedResponse = new Response(200, [], '{"users":[]}');

        $innerClient = $this->createMock(ClientInterface::class);
        $innerClient->expects(self::once())
            ->method('sendRequest')
            ->with($request)
            ->willReturn($expectedResponse);

        $storage = $this->createStub(StorageInterface::class);
        $interceptor = new SentinelInterceptor($storage, InterceptorConfig::minimal());

        $client = new SentinelClient($innerClient, $interceptor);
        $response = $client->sendRequest($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"users":[]}', (string) $response->getBody());
    }

    #[Test]
    public function it_intercepts_request_and_response(): void
    {
        $capturedRecord = null;

        $request = new Request(
            'POST',
            'https://api.example.com/users',
            ['Content-Type' => 'application/json'],
            '{"name":"John"}'
        );
        $response = new Response(
            201,
            ['X-Request-Id' => 'abc123'],
            '{"id":1,"name":"John"}'
        );

        $innerClient = $this->createStub(ClientInterface::class);
        $innerClient->method('sendRequest')->willReturn($response);

        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())
            ->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $config = new InterceptorConfig(redactPii: false);
        $interceptor = new SentinelInterceptor($storage, $config);

        $client = new SentinelClient($innerClient, $interceptor);
        $client->sendRequest($request);

        self::assertNotNull($capturedRecord);
        self::assertSame('POST', $capturedRecord->method);
        self::assertSame('https://api.example.com/users', $capturedRecord->url);
        self::assertSame(201, $capturedRecord->statusCode);
        self::assertSame('{"name":"John"}', $capturedRecord->requestBody);
        self::assertSame('{"id":1,"name":"John"}', $capturedRecord->responseBody);
        self::assertGreaterThan(0, $capturedRecord->latencyMs);
    }

    #[Test]
    public function it_uses_id_generator_when_provided(): void
    {
        $capturedRecord = null;

        $innerClient = $this->createStub(ClientInterface::class);
        $innerClient->method('sendRequest')->willReturn(new Response(200));

        $storage = $this->createStub(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $idGenerator = $this->createStub(IdGeneratorInterface::class);
        $idGenerator->method('generate')->willReturn('custom-id-123');

        $interceptor = new SentinelInterceptor($storage, InterceptorConfig::minimal());
        $client = new SentinelClient($innerClient, $interceptor, $idGenerator);

        $client->sendRequest(new Request('GET', 'https://api.example.com/test'));

        self::assertNotNull($capturedRecord);
        self::assertSame('custom-id-123', $capturedRecord->id);
    }

    #[Test]
    public function it_flattens_single_value_headers(): void
    {
        $capturedRecord = null;

        $request = new Request('GET', 'https://api.example.com/test', ['Accept' => 'application/json']);
        $response = new Response(200, ['Content-Type' => 'application/json']);

        $innerClient = $this->createStub(ClientInterface::class);
        $innerClient->method('sendRequest')->willReturn($response);

        $storage = $this->createStub(StorageInterface::class);
        $storage->method('store')
            ->willReturnCallback(function (ApiCallRecord $record) use (&$capturedRecord): void {
                $capturedRecord = $record;
            });

        $config = new InterceptorConfig(redactPii: false, captureHeaders: true);
        $interceptor = new SentinelInterceptor($storage, $config);

        $client = new SentinelClient($innerClient, $interceptor);
        $client->sendRequest($request);

        self::assertNotNull($capturedRecord);
        self::assertSame('application/json', $capturedRecord->requestHeaders['Accept']);
        self::assertSame('application/json', $capturedRecord->responseHeaders['Content-Type']);
    }

    #[Test]
    public function it_rewinds_response_body_after_reading(): void
    {
        $innerClient = $this->createStub(ClientInterface::class);
        $innerClient->method('sendRequest')
            ->willReturn(new Response(200, [], '{"data":"test"}'));

        $storage = $this->createStub(StorageInterface::class);
        $interceptor = new SentinelInterceptor($storage, InterceptorConfig::minimal());

        $client = new SentinelClient($innerClient, $interceptor);
        $response = $client->sendRequest(new Request('GET', 'https://api.example.com/test'));

        // Body should be readable after interception
        self::assertSame('{"data":"test"}', (string) $response->getBody());
    }

    #[Test]
    public function it_propagates_client_exceptions_without_intercepting(): void
    {
        $exception = new class ('Connection failed') extends \Exception implements ClientExceptionInterface {};

        $innerClient = $this->createStub(ClientInterface::class);
        $innerClient->method('sendRequest')
            ->willThrowException($exception);

        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::never())->method('store');

        $interceptor = new SentinelInterceptor($storage, InterceptorConfig::minimal());
        $client = new SentinelClient($innerClient, $interceptor);

        $this->expectException(ClientExceptionInterface::class);
        $this->expectExceptionMessage('Connection failed');

        $client->sendRequest(new Request('GET', 'https://api.example.com/test'));
    }
}
