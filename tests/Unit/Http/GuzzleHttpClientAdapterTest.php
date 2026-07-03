<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\GuzzleHttpClientAdapter;
use App\Http\HttpClientException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(GuzzleHttpClientAdapter::class)]
final class GuzzleHttpClientAdapterTest extends TestCase
{
    private ClientInterface&MockObject $guzzleClient;
    private GuzzleHttpClientAdapter $adapter;

    protected function setUp(): void
    {
        $this->guzzleClient = $this->createMock(ClientInterface::class);
        $this->adapter = new GuzzleHttpClientAdapter($this->guzzleClient);
    }

    #[Test]
    public function requestSendsRequestAndReturnsResponse(): void
    {
        $guzzleResponse = new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"result": "success"}'
        );

        $this->guzzleClient
            ->expects(self::once())
            ->method('send')
            ->with(
                self::callback(function (RequestInterface $request): bool {
                    return $request->getMethod() === 'POST'
                        && (string) $request->getUri() === 'https://api.example.com/test'
                        && $request->getHeaderLine('Authorization') === 'Bearer token'
                        && (string) $request->getBody() === '{"data": "test"}';
                }),
                self::callback(function (array $options): bool {
                    return $options['http_errors'] === false
                        && $options['timeout'] === 30.0
                        && $options['connect_timeout'] === 10.0;
                })
            )
            ->willReturn($guzzleResponse);

        $response = $this->adapter->request(
            'POST',
            'https://api.example.com/test',
            ['Authorization' => 'Bearer token'],
            '{"data": "test"}'
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertSame('{"result": "success"}', $response->body);
    }

    #[Test]
    public function requestThrowsHttpClientExceptionOnGuzzleException(): void
    {
        $this->guzzleClient
            ->expects(self::once())
            ->method('send')
            ->willThrowException(new ConnectException(
                'Connection refused',
                new Request('GET', 'https://api.example.com/test')
            ));

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/Connection refused/');

        $this->adapter->request('GET', 'https://api.example.com/test');
    }

    #[Test]
    public function requestHandlesNullBody(): void
    {
        $guzzleResponse = new Response(204, [], '');

        $this->guzzleClient
            ->expects(self::once())
            ->method('send')
            ->willReturn($guzzleResponse);

        $response = $this->adapter->request('DELETE', 'https://api.example.com/resource/1');

        self::assertSame(204, $response->statusCode);
        self::assertSame('', $response->body);
    }

    #[Test]
    public function requestUsesCustomTimeoutSettings(): void
    {
        $customAdapter = new GuzzleHttpClientAdapter(
            $this->guzzleClient,
            timeout: 60.0,
            connectTimeout: 5.0
        );

        $guzzleResponse = new Response(200, [], 'OK');

        $this->guzzleClient
            ->expects(self::once())
            ->method('send')
            ->with(
                self::anything(),
                self::callback(function (array $options): bool {
                    return $options['timeout'] === 60.0
                        && $options['connect_timeout'] === 5.0;
                })
            )
            ->willReturn($guzzleResponse);

        $customAdapter->request('GET', 'https://api.example.com/test');
    }
}
