<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ProxyResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(ProxyResult::class)]
final class ProxyResultTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $response = new Response('body', 200);
        $requestHeaders = ['Content-Type' => 'application/json'];
        $responseHeaders = ['X-Request-Id' => 'abc123'];

        $result = new ProxyResult(
            response: $response,
            statusCode: 200,
            requestHeaders: $requestHeaders,
            requestBody: '{"name": "test"}',
            responseHeaders: $responseHeaders,
            responseBody: '{"id": 1}',
        );

        self::assertSame($response, $result->response);
        self::assertSame(200, $result->statusCode);
        self::assertSame($requestHeaders, $result->requestHeaders);
        self::assertSame('{"name": "test"}', $result->requestBody);
        self::assertSame($responseHeaders, $result->responseHeaders);
        self::assertSame('{"id": 1}', $result->responseBody);
    }

    #[Test]
    public function constructsWithNullResponseData(): void
    {
        $response = new Response('error', 502);

        $result = new ProxyResult(
            response: $response,
            statusCode: 502,
            requestHeaders: [],
            requestBody: '',
            responseHeaders: null,
            responseBody: null,
        );

        self::assertSame(502, $result->statusCode);
        self::assertNull($result->responseHeaders);
        self::assertNull($result->responseBody);
    }

    #[Test]
    public function isReadonly(): void
    {
        $reflection = new \ReflectionClass(ProxyResult::class);

        self::assertTrue($reflection->isReadOnly());
    }
}
