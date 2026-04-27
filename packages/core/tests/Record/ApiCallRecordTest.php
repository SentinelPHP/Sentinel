<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Tests\Record;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Core\Record\ApiCallRecord;

#[CoversClass(ApiCallRecord::class)]
final class ApiCallRecordTest extends TestCase
{
    #[Test]
    public function it_creates_record_with_all_properties(): void
    {
        $timestamp = new DateTimeImmutable('2024-01-15T10:30:00Z');

        $record = new ApiCallRecord(
            method: 'POST',
            url: 'https://api.example.com/users',
            statusCode: 201,
            latencyMs: 150.5,
            timestamp: $timestamp,
            requestHeaders: ['Content-Type' => 'application/json'],
            requestBody: '{"name":"John"}',
            responseHeaders: ['X-Request-Id' => 'abc123'],
            responseBody: '{"id":1,"name":"John"}',
            generatedSchema: ['type' => 'object'],
            id: 'record-123',
        );

        self::assertSame('POST', $record->method);
        self::assertSame('https://api.example.com/users', $record->url);
        self::assertSame(201, $record->statusCode);
        self::assertSame(150.5, $record->latencyMs);
        self::assertSame($timestamp, $record->timestamp);
        self::assertSame(['Content-Type' => 'application/json'], $record->requestHeaders);
        self::assertSame('{"name":"John"}', $record->requestBody);
        self::assertSame(['X-Request-Id' => 'abc123'], $record->responseHeaders);
        self::assertSame('{"id":1,"name":"John"}', $record->responseBody);
        self::assertSame(['type' => 'object'], $record->generatedSchema);
        self::assertSame('record-123', $record->id);
    }

    #[Test]
    public function it_creates_record_with_minimal_properties(): void
    {
        $timestamp = new DateTimeImmutable();

        $record = new ApiCallRecord(
            method: 'GET',
            url: 'https://api.example.com/health',
            statusCode: 200,
            latencyMs: 10.0,
            timestamp: $timestamp,
        );

        self::assertSame('GET', $record->method);
        self::assertSame(200, $record->statusCode);
        self::assertSame([], $record->requestHeaders);
        self::assertNull($record->requestBody);
        self::assertSame([], $record->responseHeaders);
        self::assertNull($record->responseBody);
        self::assertNull($record->generatedSchema);
        self::assertNull($record->id);
    }

    #[Test]
    public function it_converts_to_array(): void
    {
        $timestamp = new DateTimeImmutable('2024-01-15T10:30:00+00:00');

        $record = new ApiCallRecord(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            latencyMs: 50.0,
            timestamp: $timestamp,
            requestHeaders: ['Accept' => 'application/json'],
            requestBody: null,
            responseHeaders: ['Content-Type' => 'application/json'],
            responseBody: '[]',
            generatedSchema: ['type' => 'array'],
            id: 'test-id',
        );

        $array = $record->toArray();

        self::assertSame('test-id', $array['id']);
        self::assertSame('GET', $array['method']);
        self::assertSame('https://api.example.com/users', $array['url']);
        self::assertSame(200, $array['statusCode']);
        self::assertSame(50.0, $array['latencyMs']);
        self::assertSame('2024-01-15T10:30:00+00:00', $array['timestamp']);
        self::assertSame(['Accept' => 'application/json'], $array['requestHeaders']);
        self::assertNull($array['requestBody']);
        self::assertSame(['Content-Type' => 'application/json'], $array['responseHeaders']);
        self::assertSame('[]', $array['responseBody']);
        self::assertSame(['type' => 'array'], $array['generatedSchema']);
    }

    #[Test]
    public function it_creates_from_array(): void
    {
        $data = [
            'id' => 'from-array-id',
            'method' => 'PUT',
            'url' => 'https://api.example.com/users/1',
            'statusCode' => 200,
            'latencyMs' => 75.5,
            'timestamp' => '2024-01-15T10:30:00+00:00',
            'requestHeaders' => ['Authorization' => 'Bearer token'],
            'requestBody' => '{"name":"Jane"}',
            'responseHeaders' => [],
            'responseBody' => '{"id":1,"name":"Jane"}',
            'generatedSchema' => ['type' => 'object'],
        ];

        $record = ApiCallRecord::fromArray($data);

        self::assertSame('from-array-id', $record->id);
        self::assertSame('PUT', $record->method);
        self::assertSame('https://api.example.com/users/1', $record->url);
        self::assertSame(200, $record->statusCode);
        self::assertSame(75.5, $record->latencyMs);
        self::assertSame('2024-01-15T10:30:00+00:00', $record->timestamp->format(DateTimeImmutable::ATOM));
        self::assertSame(['Authorization' => 'Bearer token'], $record->requestHeaders);
        self::assertSame('{"name":"Jane"}', $record->requestBody);
        self::assertSame([], $record->responseHeaders);
        self::assertSame('{"id":1,"name":"Jane"}', $record->responseBody);
        self::assertSame(['type' => 'object'], $record->generatedSchema);
    }

    #[Test]
    public function it_creates_from_array_with_datetime_object(): void
    {
        $timestamp = new DateTimeImmutable('2024-06-01T12:00:00Z');

        $data = [
            'method' => 'DELETE',
            'url' => 'https://api.example.com/users/1',
            'statusCode' => 204,
            'latencyMs' => 25.0,
            'timestamp' => $timestamp,
        ];

        $record = ApiCallRecord::fromArray($data);

        self::assertSame($timestamp, $record->timestamp);
    }

    #[Test]
    public function it_creates_from_array_with_defaults(): void
    {
        $record = ApiCallRecord::fromArray([]);

        self::assertSame('GET', $record->method);
        self::assertSame('', $record->url);
        self::assertSame(0, $record->statusCode);
        self::assertSame(0.0, $record->latencyMs);
        self::assertSame([], $record->requestHeaders);
        self::assertNull($record->requestBody);
        self::assertSame([], $record->responseHeaders);
        self::assertNull($record->responseBody);
        self::assertNull($record->generatedSchema);
        self::assertNull($record->id);
    }

    #[Test]
    public function it_coerces_wrong_types_in_from_array(): void
    {
        $data = [
            'method' => 123,
            'url' => null,
            'statusCode' => '404',
            'latencyMs' => '99.5',
            'timestamp' => null,
            'requestHeaders' => 'not-an-array',
            'requestBody' => 12345,
            'responseHeaders' => null,
            'responseBody' => ['should', 'be', 'string'],
            'generatedSchema' => 'not-an-array',
            'id' => 999,
        ];

        $record = ApiCallRecord::fromArray($data);

        self::assertSame('GET', $record->method);
        self::assertSame('', $record->url);
        self::assertSame(404, $record->statusCode);
        self::assertSame(99.5, $record->latencyMs);
        self::assertInstanceOf(DateTimeImmutable::class, $record->timestamp);
        self::assertSame([], $record->requestHeaders);
        self::assertNull($record->requestBody);
        self::assertSame([], $record->responseHeaders);
        self::assertNull($record->responseBody);
        self::assertNull($record->generatedSchema);
        self::assertNull($record->id);
    }

    #[Test]
    public function it_round_trips_through_to_array_and_from_array(): void
    {
        $timestamp = new DateTimeImmutable('2024-03-20T15:45:30+00:00');

        $original = new ApiCallRecord(
            method: 'PATCH',
            url: 'https://api.example.com/items/42',
            statusCode: 200,
            latencyMs: 123.456,
            timestamp: $timestamp,
            requestHeaders: ['Content-Type' => 'application/json', 'X-Custom' => ['a', 'b']],
            requestBody: '{"status":"active"}',
            responseHeaders: ['X-Request-Id' => 'req-123'],
            responseBody: '{"id":42,"status":"active"}',
            generatedSchema: ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            id: 'round-trip-id',
        );

        $array = $original->toArray();
        $restored = ApiCallRecord::fromArray($array);

        self::assertSame($original->method, $restored->method);
        self::assertSame($original->url, $restored->url);
        self::assertSame($original->statusCode, $restored->statusCode);
        self::assertSame($original->latencyMs, $restored->latencyMs);
        self::assertSame(
            $original->timestamp->format(DateTimeImmutable::ATOM),
            $restored->timestamp->format(DateTimeImmutable::ATOM)
        );
        self::assertSame($original->requestHeaders, $restored->requestHeaders);
        self::assertSame($original->requestBody, $restored->requestBody);
        self::assertSame($original->responseHeaders, $restored->responseHeaders);
        self::assertSame($original->responseBody, $restored->responseBody);
        self::assertSame($original->generatedSchema, $restored->generatedSchema);
        self::assertSame($original->id, $restored->id);
    }
}
