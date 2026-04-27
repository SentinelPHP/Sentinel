<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Entity\ApiToken;
use App\Enum\LogLevel;
use App\Message\RequestLogMessage;
use App\Message\SchemaLearnMessage;
use App\Message\SchemaValidateMessage;
use App\Storage\MessengerStorage;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Core\Record\ApiCallRecord;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MessengerStorage::class)]
final class MessengerStorageTest extends TestCase
{
    private MessageBusInterface&Stub $messageBus;
    private ApiToken&Stub $token;

    protected function setUp(): void
    {
        $this->messageBus = $this->createStub(MessageBusInterface::class);
        $this->token = $this->createStub(ApiToken::class);

        $tokenId = Uuid::v7();
        $this->token->method('getId')->willReturn($tokenId);
        $this->token->method('getLogLevel')->willReturn(LogLevel::FullAudit);
    }

    #[Test]
    public function it_dispatches_request_log_message(): void
    {
        $dispatchedMessages = [];

        $this->messageBus->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $message;
                return new Envelope($message);
            });

        $storage = new MessengerStorage($this->messageBus, $this->token);
        $storage->store($this->createRecord());

        $requestLogMessages = array_filter(
            $dispatchedMessages,
            fn ($m) => $m instanceof RequestLogMessage
        );

        self::assertCount(1, $requestLogMessages);

        /** @var RequestLogMessage $message */
        $message = array_values($requestLogMessages)[0];
        self::assertSame('GET', $message->requestMethod);
        self::assertSame('api.example.com', $message->targetHost);
        self::assertSame('/users', $message->requestPath);
        self::assertSame(200, $message->responseStatusCode);
        self::assertSame(50, $message->latencyMs);
    }

    #[Test]
    public function it_dispatches_schema_learn_message(): void
    {
        $dispatchedMessages = [];

        $this->messageBus->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $message;
                return new Envelope($message);
            });

        $storage = new MessengerStorage($this->messageBus, $this->token);
        $storage->store($this->createRecord());

        $schemaLearnMessages = array_filter(
            $dispatchedMessages,
            fn ($m) => $m instanceof SchemaLearnMessage
        );

        self::assertCount(1, $schemaLearnMessages);

        /** @var SchemaLearnMessage $message */
        $message = array_values($schemaLearnMessages)[0];
        self::assertSame('GET', $message->method);
        self::assertSame('api.example.com', $message->targetHost);
        self::assertSame('/users', $message->path);
        self::assertSame('{"users":[]}', $message->responseBody);
    }

    #[Test]
    public function it_dispatches_schema_validate_message(): void
    {
        $dispatchedMessages = [];

        $this->messageBus->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $message;
                return new Envelope($message);
            });

        $storage = new MessengerStorage($this->messageBus, $this->token);
        $storage->store($this->createRecord());

        $schemaValidateMessages = array_filter(
            $dispatchedMessages,
            fn ($m) => $m instanceof SchemaValidateMessage
        );

        self::assertCount(1, $schemaValidateMessages);

        /** @var SchemaValidateMessage $message */
        $message = array_values($schemaValidateMessages)[0];
        self::assertSame('GET', $message->method);
        self::assertSame('api.example.com', $message->targetHost);
        self::assertSame('/users', $message->path);
        self::assertSame('{"users":[]}', $message->responseBody);
    }

    #[Test]
    public function it_skips_schema_messages_without_response_body(): void
    {
        $dispatchedMessages = [];

        $this->messageBus->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $message;
                return new Envelope($message);
            });

        $record = new ApiCallRecord(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 204,
            latencyMs: 50.0,
            timestamp: new DateTimeImmutable(),
            responseBody: null,
        );

        $storage = new MessengerStorage($this->messageBus, $this->token);
        $storage->store($record);

        $schemaMessages = array_filter(
            $dispatchedMessages,
            fn ($m) => $m instanceof SchemaLearnMessage || $m instanceof SchemaValidateMessage
        );

        self::assertCount(0, $schemaMessages);
    }

    #[Test]
    public function it_skips_request_log_when_log_level_is_none(): void
    {
        $this->token = $this->createStub(ApiToken::class);
        $this->token->method('getId')->willReturn(Uuid::v7());
        $this->token->method('getLogLevel')->willReturn(LogLevel::None);

        $dispatchedMessages = [];

        $this->messageBus->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $message;
                return new Envelope($message);
            });

        $storage = new MessengerStorage($this->messageBus, $this->token);
        $storage->store($this->createRecord());

        $requestLogMessages = array_filter(
            $dispatchedMessages,
            fn ($m) => $m instanceof RequestLogMessage
        );

        self::assertCount(0, $requestLogMessages);
    }

    #[Test]
    public function it_extracts_host_and_path_from_url(): void
    {
        $dispatchedMessages = [];

        $this->messageBus->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $message;
                return new Envelope($message);
            });

        $record = new ApiCallRecord(
            method: 'GET',
            url: 'https://api.example.com/users?page=1&limit=10',
            statusCode: 200,
            latencyMs: 50.0,
            timestamp: new DateTimeImmutable(),
            responseBody: '[]',
        );

        $storage = new MessengerStorage($this->messageBus, $this->token);
        $storage->store($record);

        /** @var RequestLogMessage $message */
        $message = array_values(array_filter(
            $dispatchedMessages,
            fn ($m) => $m instanceof RequestLogMessage
        ))[0];

        self::assertSame('api.example.com', $message->targetHost);
        self::assertSame('/users?page=1&limit=10', $message->requestPath);
    }

    private function createRecord(): ApiCallRecord
    {
        return new ApiCallRecord(
            method: 'GET',
            url: 'https://api.example.com/users',
            statusCode: 200,
            latencyMs: 50.0,
            timestamp: new DateTimeImmutable(),
            requestHeaders: ['Accept' => 'application/json'],
            responseHeaders: ['Content-Type' => 'application/json'],
            responseBody: '{"users":[]}',
            id: 'test-record-id',
        );
    }
}
