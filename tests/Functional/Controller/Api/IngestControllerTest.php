<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\ApiToken;
use App\Message\RequestLogMessage;
use App\Message\SchemaLearnMessage;
use App\Message\SchemaValidateMessage;
use App\Tests\Factories\ApiTokenFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class IngestControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;
    private string $plainToken = 'test-api-token-12345';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        ApiTokenFactory::new()
            ->withKnownToken($this->plainToken)
            ->create();
    }

    // ==================== AUTHENTICATION TESTS ====================

    public function testIngestRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/ingest', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->createValidPayload(), JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(401);
        $this->assertErrorResponse('Missing or invalid Authorization header');
    }

    public function testIngestRejectsInvalidToken(): void
    {
        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer invalid-token',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->createValidPayload(), JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(401);
        $this->assertErrorResponse('Invalid API token');
    }

    public function testIngestRejectsInactiveToken(): void
    {
        ApiTokenFactory::new()
            ->withKnownToken('inactive-token')
            ->inactive()
            ->create();

        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer inactive-token',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->createValidPayload(), JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(401);
        $this->assertErrorResponse('Invalid API token');
    }

    // ==================== VALIDATION TESTS ====================

    public function testIngestRejectsEmptyBody(): void
    {
        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], '');

        self::assertResponseStatusCodeSame(400);
        $this->assertErrorResponse('Request body is required');
    }

    public function testIngestRejectsInvalidJson(): void
    {
        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], 'not valid json');

        self::assertResponseStatusCodeSame(400);
        $data = $this->getJsonResponse();
        self::assertTrue($data['error'] ?? false);
        $message = $data['message'] ?? '';
        self::assertIsString($message);
        self::assertStringContainsString('Invalid JSON', $message);
    }

    public function testIngestRejectsMissingRequiredFields(): void
    {
        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'GET'], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
        $data = $this->getJsonResponse();
        self::assertTrue($data['error'] ?? false);
        $message = $data['message'] ?? '';
        self::assertIsString($message);
        self::assertStringContainsString('Missing required fields', $message);
        self::assertStringContainsString('url', $message);
        self::assertStringContainsString('statusCode', $message);
        self::assertStringContainsString('latencyMs', $message);
    }

    public function testIngestRejectsInvalidMethodType(): void
    {
        $payload = $this->createValidPayload();
        $payload['method'] = 123;

        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
        $this->assertErrorResponse('Field "method" must be a string');
    }

    public function testIngestRejectsInvalidUrlType(): void
    {
        $payload = $this->createValidPayload();
        $payload['url'] = ['not', 'a', 'string'];

        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
        $this->assertErrorResponse('Field "url" must be a string');
    }

    public function testIngestRejectsInvalidStatusCodeType(): void
    {
        $payload = $this->createValidPayload();
        $payload['statusCode'] = 'not a number';

        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
        $this->assertErrorResponse('Field "statusCode" must be a number');
    }

    // ==================== SUCCESS TESTS ====================

    public function testIngestReturns202WithValidPayload(): void
    {
        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->createValidPayload(), JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(202);
        $data = $this->getJsonResponse();
        self::assertTrue($data['success'] ?? false);
    }

    public function testIngestDispatchesRequestLogMessage(): void
    {
        $payload = $this->createValidPayload();
        $payload['responseBody'] = '{"users":[]}';

        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(202);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = $transport->getSent();

        $requestLogMessages = array_filter(
            $messages,
            fn ($envelope) => $envelope->getMessage() instanceof RequestLogMessage
        );

        self::assertCount(1, $requestLogMessages);

        /** @var RequestLogMessage $message */
        $message = array_values($requestLogMessages)[0]->getMessage();
        self::assertSame('GET', $message->requestMethod);
        self::assertSame('api.example.com', $message->targetHost);
        self::assertSame('/users', $message->requestPath);
        self::assertSame(200, $message->responseStatusCode);
    }

    public function testIngestDispatchesSchemaLearnMessage(): void
    {
        $payload = $this->createValidPayload();
        $payload['responseBody'] = '{"users":[]}';

        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(202);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = $transport->getSent();

        $schemaLearnMessages = array_filter(
            $messages,
            fn ($envelope) => $envelope->getMessage() instanceof SchemaLearnMessage
        );

        self::assertCount(1, $schemaLearnMessages);

        /** @var SchemaLearnMessage $message */
        $message = array_values($schemaLearnMessages)[0]->getMessage();
        self::assertSame('GET', $message->method);
        self::assertSame('api.example.com', $message->targetHost);
        self::assertSame('/users', $message->path);
        self::assertSame('{"users":[]}', $message->responseBody);
    }

    public function testIngestDispatchesSchemaValidateMessage(): void
    {
        $payload = $this->createValidPayload();
        $payload['responseBody'] = '{"users":[]}';

        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(202);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = $transport->getSent();

        $schemaValidateMessages = array_filter(
            $messages,
            fn ($envelope) => $envelope->getMessage() instanceof SchemaValidateMessage
        );

        self::assertCount(1, $schemaValidateMessages);

        /** @var SchemaValidateMessage $message */
        $message = array_values($schemaValidateMessages)[0]->getMessage();
        self::assertSame('GET', $message->method);
        self::assertSame('api.example.com', $message->targetHost);
        self::assertSame('/users', $message->path);
        self::assertSame('{"users":[]}', $message->responseBody);
    }

    public function testIngestDoesNotDispatchSchemaMessagesWithoutResponseBody(): void
    {
        $payload = $this->createValidPayload();
        unset($payload['responseBody']);

        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(202);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = $transport->getSent();

        $schemaMessages = array_filter(
            $messages,
            fn ($envelope) => $envelope->getMessage() instanceof SchemaLearnMessage
                || $envelope->getMessage() instanceof SchemaValidateMessage
        );

        self::assertCount(0, $schemaMessages);
    }

    public function testIngestHandlesUrlWithQueryString(): void
    {
        $payload = $this->createValidPayload();
        $payload['url'] = 'https://api.example.com/users?page=1&limit=10';
        $payload['responseBody'] = '[]';

        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(202);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = $transport->getSent();

        $requestLogMessages = array_filter(
            $messages,
            fn ($envelope) => $envelope->getMessage() instanceof RequestLogMessage
        );

        /** @var RequestLogMessage $message */
        $message = array_values($requestLogMessages)[0]->getMessage();
        self::assertSame('/users?page=1&limit=10', $message->requestPath);
    }

    // ==================== HELPER METHODS ====================

    /**
     * @return array<string, mixed>
     */
    private function createValidPayload(): array
    {
        return [
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
            'statusCode' => 200,
            'latencyMs' => 50.5,
            'requestHeaders' => ['Accept' => 'application/json'],
            'responseHeaders' => ['Content-Type' => 'application/json'],
            'responseBody' => '{"users":[]}',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonResponse(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);

        /** @var array<string, mixed> */
        return $data;
    }

    private function assertErrorResponse(string $expectedMessage): void
    {
        $data = $this->getJsonResponse();
        self::assertTrue($data['error'] ?? false);
        self::assertSame($expectedMessage, $data['message'] ?? '');
    }
}
