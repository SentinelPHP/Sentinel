<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpResponse::class)]
final class HttpResponseTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $response = new HttpResponse(
            200,
            ['Content-Type' => 'application/json'],
            '{"data": "test"}'
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame(['Content-Type' => 'application/json'], $response->headers);
        self::assertSame('{"data": "test"}', $response->body);
    }

    #[Test]
    public function getHeaderReturnsValueCaseInsensitive(): void
    {
        $response = new HttpResponse(
            200,
            ['Content-Type' => 'application/json', 'X-Custom' => 'value'],
            ''
        );

        self::assertSame('application/json', $response->getHeader('Content-Type'));
        self::assertSame('application/json', $response->getHeader('content-type'));
        self::assertSame('application/json', $response->getHeader('CONTENT-TYPE'));
        self::assertSame('value', $response->getHeader('x-custom'));
    }

    #[Test]
    public function getHeaderReturnsNullForMissingHeader(): void
    {
        $response = new HttpResponse(200, [], '');

        self::assertNull($response->getHeader('X-Missing'));
    }

    #[Test]
    public function getHeaderReturnsFirstValueFromArray(): void
    {
        $response = new HttpResponse(
            200,
            ['Set-Cookie' => ['cookie1=value1', 'cookie2=value2']],
            ''
        );

        self::assertSame('cookie1=value1', $response->getHeader('Set-Cookie'));
    }
}
