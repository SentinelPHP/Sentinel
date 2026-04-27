<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Tests\Storage;

use DateTimeImmutable;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use SentinelPHP\Core\Record\ApiCallRecord;
use SentinelPHP\Core\Storage\SentinelHttpStorage;
use SentinelPHP\Core\Storage\StorageException;

#[CoversClass(SentinelHttpStorage::class)]
#[AllowMockObjectsWithoutExpectations]
final class SentinelHttpStorageTest extends TestCase
{
    private HttpFactory $httpFactory;

    protected function setUp(): void
    {
        $this->httpFactory = new HttpFactory();
    }

    #[Test]
    public function it_sends_record_to_sentinel_server(): void
    {
        $capturedRequest = null;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedRequest): Response {
                $capturedRequest = $request;
                return new Response(202);
            });

        $storage = $this->createStorage($httpClient);
        $storage->store($this->createRecord());

        self::assertNotNull($capturedRequest);
        self::assertSame('POST', $capturedRequest->getMethod());
        self::assertSame('https://sentinel.example.com/api/ingest', (string) $capturedRequest->getUri());
    }

    #[Test]
    public function it_sends_authorization_header(): void
    {
        $capturedRequest = null;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedRequest): Response {
                $capturedRequest = $request;
                return new Response(202);
            });

        $storage = $this->createStorage($httpClient, apiToken: 'test-token-123');
        $storage->store($this->createRecord());

        self::assertNotNull($capturedRequest);
        self::assertSame('Bearer test-token-123', $capturedRequest->getHeaderLine('Authorization'));
    }

    #[Test]
    public function it_sends_json_content_type(): void
    {
        $capturedRequest = null;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedRequest): Response {
                $capturedRequest = $request;
                return new Response(202);
            });

        $storage = $this->createStorage($httpClient);
        $storage->store($this->createRecord());

        self::assertNotNull($capturedRequest);
        self::assertSame('application/json', $capturedRequest->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function it_sends_record_as_json_body(): void
    {
        $capturedRequest = null;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedRequest): Response {
                $capturedRequest = $request;
                return new Response(202);
            });

        $record = $this->createRecord();
        $storage = $this->createStorage($httpClient);
        $storage->store($record);

        self::assertNotNull($capturedRequest);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $capturedRequest->getBody(), true);

        self::assertSame($record->method, $decoded['method']);
        self::assertSame($record->url, $decoded['url']);
        self::assertSame($record->statusCode, $decoded['statusCode']);
        self::assertEqualsWithDelta($record->latencyMs, $decoded['latencyMs'], 0.001);
        self::assertSame($record->id, $decoded['id']);
    }

    #[Test]
    public function it_throws_storage_exception_on_http_error(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturn(new Response(500));

        $storage = $this->createStorage($httpClient);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('SentinelPHP server returned HTTP 500');

        $storage->store($this->createRecord());
    }

    #[Test]
    public function it_throws_storage_exception_on_4xx_error(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturn(new Response(401));

        $storage = $this->createStorage($httpClient);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('SentinelPHP server returned HTTP 401');

        $storage->store($this->createRecord());
    }

    #[Test]
    public function it_throws_storage_exception_on_client_exception(): void
    {
        $exception = new class ('Connection refused') extends \Exception implements ClientExceptionInterface {};

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willThrowException($exception);

        $storage = $this->createStorage($httpClient);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('HTTP request failed: Connection refused');

        $storage->store($this->createRecord());
    }

    #[Test]
    public function it_suppresses_exceptions_when_throw_on_error_is_false(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturn(new Response(500));

        $storage = $this->createStorage($httpClient, throwOnError: false);

        // Should not throw
        $storage->store($this->createRecord());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_suppresses_client_exceptions_when_throw_on_error_is_false(): void
    {
        $exception = new class ('Connection refused') extends \Exception implements ClientExceptionInterface {};

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willThrowException($exception);

        $storage = $this->createStorage($httpClient, throwOnError: false);

        // Should not throw
        $storage->store($this->createRecord());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_handles_base_url_with_trailing_slash(): void
    {
        $capturedRequest = null;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedRequest): Response {
                $capturedRequest = $request;
                return new Response(202);
            });

        $storage = $this->createStorage($httpClient, baseUrl: 'https://sentinel.example.com/');
        $storage->store($this->createRecord());

        self::assertNotNull($capturedRequest);
        self::assertSame('https://sentinel.example.com/api/ingest', (string) $capturedRequest->getUri());
    }

    private function createStorage(
        ClientInterface $httpClient,
        string $baseUrl = 'https://sentinel.example.com',
        string $apiToken = 'test-token',
        bool $throwOnError = true,
    ): SentinelHttpStorage {
        return new SentinelHttpStorage(
            httpClient: $httpClient,
            requestFactory: $this->httpFactory,
            streamFactory: $this->httpFactory,
            baseUrl: $baseUrl,
            apiToken: $apiToken,
            throwOnError: $throwOnError,
        );
    }

    private function createRecord(): ApiCallRecord
    {
        return new ApiCallRecord(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            latencyMs: 50.0,
            timestamp: new DateTimeImmutable('2024-01-15T10:30:00Z'),
            requestHeaders: ['Accept' => 'application/json'],
            requestBody: null,
            responseHeaders: ['Content-Type' => 'application/json'],
            responseBody: '{"users":[]}',
            id: 'test-record-id',
        );
    }
}
